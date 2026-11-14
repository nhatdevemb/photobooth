<?php

declare(strict_types=1);

namespace Photobooth\Operator\Image;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Support\ApiException;

final class Renderer
{
    private AppConfig $config;
    private StructuredLogger $logger;

    public function __construct(AppConfig $config, StructuredLogger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Validate a captured JPEG via the Python image pipeline (`--validate-jpeg`).
     *
     * @return array{
     *   sha256: string,
     *   width: int,
     *   height: int,
     *   bytes: int,
     *   exif_orientation: int,
     *   icc_status: string
     * }
     */
    public function validateJpeg(string $filePath): array
    {
        $scriptPath = $this->config->customRoot . '/python/render_pipeline.py';
        $cmd = [
            'python3',
            $scriptPath,
            '--validate-jpeg',
            $filePath,
            (string) $this->config->maxJpegBytes,
            (string) $this->config->maxImagePixels,
        ];

        $result = $this->runPythonCommand($cmd, $this->config->captureTimeoutSeconds);
        if (($result['status'] ?? '') !== 'ok') {
            $code = (string) ($result['code'] ?? 'INVALID_JPEG');
            $msg = match ($code) {
                'CORRUPT_JPEG', 'INVALID_JPEG' => 'Tệp ảnh chụp bị hỏng hoặc không đúng định dạng JPEG.',
                'IMAGE_TOO_LARGE' => 'Dung lượng tệp ảnh vượt quá giới hạn 50 MiB.',
                'IMAGE_PIXELS_EXCEEDED' => 'Độ phân giải ảnh vượt quá giới hạn 60 Megapixel.',
                'INVALID_ICC_PROFILE' => 'Hồ sơ màu ICC trong ảnh bị lỗi hoặc không hợp lệ.',
                default => 'Không thể xác thực tệp ảnh đầu vào.',
            };
            throw new ApiException($code, $msg, 422);
        }

        return [
            'sha256' => (string) $result['sha256'],
            'width' => (int) $result['width'],
            'height' => (int) $result['height'],
            'bytes' => (int) $result['bytes'],
            'exif_orientation' => (int) ($result['exif_orientation'] ?? 1),
            'icc_status' => (string) ($result['icc_status'] ?? 'assumed_srgb'),
        ];
    }

    /**
     * Execute a full 3-photo LUT + collage render using a JSON manifest in the job's pending directory.
     *
     * @param array<string, mixed> $manifestData
     * @return array<string, mixed>
     */
    public function renderFromManifest(string $pendingDir, array $manifestData, ?string $jobId = null): array
    {
        if (!is_dir($pendingDir)) {
            @mkdir($pendingDir, 0750, true);
        }

        $manifestPath = rtrim($pendingDir, '/') . '/render-manifest.json';
        file_put_contents(
            $manifestPath,
            json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $scriptPath = $this->config->customRoot . '/python/render_pipeline.py';
        $cmd = ['python3', $scriptPath, $manifestPath];

        $result = $this->runPythonCommand($cmd, $this->config->renderTimeoutSeconds, $jobId);
        if (($result['status'] ?? '') !== 'ok') {
            $code = (string) ($result['code'] ?? 'RENDER_FAILED');
            $msg = match ($code) {
                'INVALID_LUT' => 'Bộ lọc màu (LUT) bị lỗi hoặc không đúng chuẩn .cube size 33.',
                'INVALID_ICC_PROFILE' => 'Ảnh nguồn chứa hồ sơ màu ICC bị hỏng, không thể chuẩn hóa sRGB.',
                'CORRUPT_JPEG', 'INVALID_JPEG' => 'Ảnh nguồn bị hỏng định dạng JPEG.',
                'MISSING_PHOTOS', 'MISSING_PHOTO_FILE' => 'Thiếu tệp ảnh nguồn cho một hoặc nhiều slot.',
                'SOURCE_HASH_MISMATCH' => 'Mã kiểm tra SHA-256 của ảnh gốc không khớp.',
                default => 'Xử lý ảnh và ghép khung thất bại.',
            };
            throw new ApiException($code, $msg, 422);
        }

        return $result;
    }

    public function previewLut(string $filePath, string $outputPath, int $intensity): array
    {
        $result = $this->runPythonCommand(['python3', $this->config->customRoot . '/python/lut_preview.py', $filePath, $outputPath, (string) $intensity], $this->config->renderTimeoutSeconds);
        if (($result['status'] ?? '') !== 'ok') {
            throw new ApiException('INVALID_LUT', 'LUT không hợp lệ: ' . ($result['message'] ?? 'Không thể đọc hoặc dựng ảnh mẫu.'), 422);
        }
        return $result;
    }

    /**
     * Run a Python command via proc_open with argv (no shell) and process-group timeout supervision.
     *
     * @param list<string> $cmd
     * @return array<string, mixed>
     */
    private function runPythonCommand(array $cmd, int $timeoutSeconds, ?string $jobId = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new ApiException('RENDER_FAILED', 'Không thể khởi chạy tiến trình xử lý ảnh Python.', 500);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $startTime) > $timeoutSeconds) {
                $timedOut = true;
                $pid = (int) $status['pid'];
                if ($pid > 0 && function_exists('posix_kill')) {
                    @posix_kill($pid, 15); // SIGTERM
                    usleep(100_000);
                    @posix_kill($pid, 9);  // SIGKILL
                } else {
                    @proc_terminate($proc, 9);
                }
                break;
            }

            usleep(20_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if (trim($stderr) !== '') {
            $this->logger->warning('python_pipeline_stderr', 'PIPELINE_STDERR', [
                'job_id' => $jobId,
                'exit_code' => $exitCode,
                'stderr' => trim($stderr),
            ]);
        }

        if ($timedOut) {
            $this->logger->error('python_pipeline_timeout', 'RENDER_TIMEOUT', [
                'job_id' => $jobId,
                'timeout_seconds' => $timeoutSeconds,
            ]);
            throw new ApiException(
                'RENDER_TIMEOUT',
                'Quá thời gian xử lý ảnh (timeout). Tiến trình đã được thu hồi an toàn.',
                504
            );
        }

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            $this->logger->error('python_pipeline_invalid_output', 'RENDER_FAILED', [
                'job_id' => $jobId,
                'exit_code' => $exitCode,
            ]);
            throw new ApiException('RENDER_FAILED', 'Tiến trình xử lý ảnh trả về kết quả không hợp lệ.', 500);
        }

        return $decoded;
    }
}

