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
            $this->config->pythonBin,
            $scriptPath,
            '--validate-jpeg',
            $filePath,
            (string) $this->config->maxJpegBytes,
            (string) $this->config->maxImagePixels,
        ];

        $result = $this->callDaemonOrFallback(
            '/validate-jpeg',
            [
                'file_path' => $filePath,
                'max_jpeg_bytes' => $this->config->maxJpegBytes,
                'max_image_pixels' => $this->config->maxImagePixels,
            ],
            static fn (): array => $cmd,
            $this->config->captureTimeoutSeconds
        );
        if (($result['status'] ?? '') !== 'ok') {
            $code = (string) ($result['code'] ?? 'INVALID_JPEG');
            if ($code === 'RENDER_TIMEOUT') {
                throw new ApiException('RENDER_TIMEOUT', 'Quá thời gian xử lý ảnh (timeout).', 504);
            }
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

        $manifestData['runtime_env'] = $this->runtimeEnvPayload();
        $manifestPath = rtrim($pendingDir, '/') . '/render-manifest.json';
        file_put_contents(
            $manifestPath,
            json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $scriptPath = $this->config->customRoot . '/python/render_pipeline.py';
        $cmd = [$this->config->pythonBin, $scriptPath, $manifestPath];

        $run = fn (): array => $this->callDaemonOrFallback(
            '/render',
            $manifestData,
            static fn (): array => $cmd,
            $this->config->renderTimeoutSeconds,
            $jobId
        );
        $result = isset($manifestData['beauty_cache_dir']) ? $this->withBeautyLock($run) : $run();
        if (($result['status'] ?? '') !== 'ok') {
            $code = (string) ($result['code'] ?? 'RENDER_FAILED');
            if ($code === 'RENDER_TIMEOUT') {
                throw new ApiException('RENDER_TIMEOUT', 'Quá thời gian xử lý ảnh (timeout).', 504);
            }
            if ($code === 'RENDER_CANCELLED') {
                $code = 'RENDER_FAILED';
            }
            $msg = match ($code) {
                'INVALID_LUT' => 'Bộ lọc màu (LUT) bị lỗi hoặc không đúng chuẩn .cube size 33.',
                'INVALID_ICC_PROFILE' => 'Ảnh nguồn chứa hồ sơ màu ICC bị hỏng, không thể chuẩn hóa sRGB.',
                'CORRUPT_JPEG', 'INVALID_JPEG' => 'Ảnh nguồn bị hỏng định dạng JPEG.',
                'MISSING_PHOTOS', 'MISSING_PHOTO_FILE' => 'Thiếu tệp ảnh nguồn cho một hoặc nhiều slot.',
                'SOURCE_HASH_MISMATCH' => 'Mã kiểm tra SHA-256 của ảnh gốc không khớp.',
                'LK_MAGIC_RETOUCH_FAILED' => 'LK Magic xử lý thất bại. Bạn có thể dựng lại với chế độ Beauty tự động.',
                default => 'Xử lý ảnh và ghép khung thất bại.',
            };
            throw new ApiException($code, $msg, 422);
        }

        return $result;
    }

    public function withBeautyLock(callable $operation, bool $blocking = true): mixed
    {
        $dir = $this->config->dataDir . '/beauty';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $lock = fopen($dir . '/processing.lock', 'c');
        if ($lock === false) {
            throw new ApiException('BEAUTY_STORAGE', 'Không mở được vùng xử lý Beauty.', 500);
        }
        try {
            if (!flock($lock, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
                if ($blocking) {
                    throw new ApiException('BEAUTY_STORAGE', 'Không khóa được vùng xử lý Beauty.', 500);
                }
                return null;
            }
            return $operation();
        } finally {
            fclose($lock);
        }
    }

    public function runBeautyTask(array $manifestData): array
    {
        $manifestData['max_jpeg_bytes'] = $this->config->maxJpegBytes;
        $manifestData['max_image_pixels'] = $this->config->maxImagePixels;
        $manifestData['runtime_env'] = $this->runtimeEnvPayload();

        $daemonResult = $this->tryDaemonRequest('/beauty-task', $manifestData, $this->config->renderTimeoutSeconds);
        if ($daemonResult !== null) {
            if (($daemonResult['status'] ?? '') === 'error' && ($daemonResult['code'] ?? '') === 'RENDER_TIMEOUT') {
                throw new ApiException('RENDER_TIMEOUT', 'Quá thời gian xử lý ảnh (timeout).', 504);
            }
            return $daemonResult;
        }

        $dir = $this->config->dataDir . '/beauty';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = tempnam($dir, 'manifest-');
        if ($file === false) {
            throw new ApiException('BEAUTY_STORAGE', 'Không tạo được tệp xử lý Beauty.', 500);
        }
        try {
            if (file_put_contents($file, json_encode($manifestData, JSON_THROW_ON_ERROR)) === false) {
                throw new ApiException('BEAUTY_STORAGE', 'Không ghi được tệp xử lý Beauty.', 500);
            }
            return $this->runPythonCommand(
                [$this->config->pythonBin, $this->config->customRoot . '/python/render_pipeline.py', $file],
                $this->config->renderTimeoutSeconds
            );
        } finally {
            @unlink($file);
        }
    }

    public function frameText(array $fields, array $slots, bool $preview = false): array
    {
        $payload = ['fields' => $fields, 'slots' => $slots, 'preview' => $preview];
        $daemonResult = $this->tryDaemonRequest('/frame-text', $payload, $this->config->renderTimeoutSeconds);
        if ($daemonResult !== null) {
            if (($daemonResult['status'] ?? '') !== 'ok') {
                $errCode = (string) ($daemonResult['code'] ?? '');
                if ($errCode === 'RENDER_TIMEOUT') {
                    throw new ApiException('RENDER_TIMEOUT', 'Quá thời gian xử lý ảnh (timeout).', 504);
                }
                if ($errCode === 'RENDER_FAILED') {
                    throw new ApiException('RENDER_FAILED', 'Tiến trình xử lý ảnh trả về kết quả không hợp lệ.', 500);
                }
                throw new ApiException('INVALID_FRAME_TEXT', $daemonResult['message'] ?? 'Không dựng được chữ trên frame.', 422);
            }
            return $daemonResult;
        }

        $file = tempnam($this->config->dataDir, 'frame-');
        if ($file === false) {
            throw new ApiException('STORAGE_ERROR', 'Không tạo được tệp xem trước frame.', 500);
        }
        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR);
            if (file_put_contents($file, $input) === false) {
                throw new ApiException('STORAGE_ERROR', 'Không ghi được tệp xem trước frame.', 500);
            }
            $result = $this->runPythonCommand(
                [$this->config->pythonBin, $this->config->customRoot . '/python/frame_text.py', $file],
                $this->config->renderTimeoutSeconds
            );
            if (($result['status'] ?? '') !== 'ok') {
                throw new ApiException('INVALID_FRAME_TEXT', $result['message'] ?? 'Không dựng được chữ trên frame.', 422);
            }
            return $result;
        } finally {
            @unlink($file);
        }
    }

    public function previewLut(string $filePath, string $outputPath, int $intensity): array
    {
        $result = $this->callDaemonOrFallback(
            '/lut-preview',
            ['lut_path' => $filePath, 'output_path' => $outputPath, 'intensity' => $intensity],
            fn (): array => [$this->config->pythonBin, $this->config->customRoot . '/python/lut_preview.py', $filePath, $outputPath, (string) $intensity],
            $this->config->renderTimeoutSeconds
        );
        if (($result['status'] ?? '') !== 'ok') {
            if (($result['code'] ?? '') === 'RENDER_TIMEOUT') {
                throw new ApiException('RENDER_TIMEOUT', 'Quá thời gian xử lý ảnh (timeout).', 504);
            }
            throw new ApiException('INVALID_LUT', 'LUT không hợp lệ: ' . ($result['message'] ?? 'Không thể đọc hoặc dựng ảnh mẫu.'), 422);
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeEnvPayload(): array
    {
        return [
            'beauty_engine' => $this->config->beautyEngine,
            'lkmagic_bridge_url' => $this->config->lkmagicBridgeUrl,
            'lkmagic_executable' => $this->config->lkmagicExecutable,
            'lkmagic_auto_start' => $this->config->lkmagicAutoStart,
        ];
    }

    /**
     * Send task to warm loopback Python Image Daemon when configured, falling back to CLI subprocess if unreachable.
     *
     * @param array<string, mixed> $payload
     * @param callable(): list<string> $fallbackCmdBuilder
     * @return array<string, mixed>
     */
    private function callDaemonOrFallback(
        string $route,
        array $payload,
        callable $fallbackCmdBuilder,
        int $timeoutSeconds,
        ?string $jobId = null
    ): array {
        $daemonResult = $this->tryDaemonRequest($route, $payload, $timeoutSeconds, $jobId);
        if ($daemonResult !== null) {
            return $daemonResult;
        }
        return $this->runPythonCommand($fallbackCmdBuilder(), $timeoutSeconds, $jobId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return ?array<string, mixed>
     */
    private function tryDaemonRequest(string $route, array $payload, int $timeoutSeconds, ?string $jobId = null): ?array
    {
        $baseUrl = rtrim($this->config->imageDaemonUrl, '/');
        if ($baseUrl === '' || !function_exists('curl_init')) {
            return null;
        }

        $payload['timeout_seconds'] = $timeoutSeconds;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return null;
        }

        $headers = ['Content-Type: application/json; charset=utf-8', 'Expect:'];
        if ($this->config->imageDaemonToken !== '') {
            $headers[] = 'X-Photobooth-Token: ' . $this->config->imageDaemonToken;
        }

        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS => max(1500, ($timeoutSeconds * 1000) + 1500),
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if (in_array($host, ['127.0.0.1', 'localhost'], true) && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        $startTime = microtime(true);
        $curl = curl_init($baseUrl . $route);
        curl_setopt_array($curl, $options);

        $responseBody = curl_exec($curl);
        $errno = curl_errno($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $connectTimeUs = defined('CURLINFO_CONNECT_TIME_T')
            ? (int) curl_getinfo($curl, CURLINFO_CONNECT_TIME_T)
            : (int) round(((float) curl_getinfo($curl, CURLINFO_CONNECT_TIME)) * 1_000_000);
        curl_close($curl);
        $elapsed = microtime(true) - $startTime;

        if ($responseBody === false) {
            // Only treat timeout as RENDER_TIMEOUT if TCP connection to daemon actually succeeded
            if ($errno === CURLE_OPERATION_TIMEDOUT && $connectTimeUs > 0 && $elapsed >= max(0.8, $timeoutSeconds - 0.5)) {
                $this->logger->error('python_daemon_timeout', 'RENDER_TIMEOUT', [
                    'job_id' => $jobId,
                    'route' => $route,
                    'timeout_seconds' => $timeoutSeconds,
                ]);
                throw new ApiException(
                    'RENDER_TIMEOUT',
                    'Quá thời gian xử lý ảnh (timeout).',
                    504
                );
            }

            $this->logger->warning('python_daemon_fallback', 'DAEMON_UNREACHABLE', [
                'job_id' => $jobId,
                'route' => $route,
                'curl_errno' => $errno,
            ]);
            return null;
        }

        if ($httpCode !== 200) {
            $this->logger->warning('python_daemon_http_fallback', 'DAEMON_HTTP_' . $httpCode, [
                'job_id' => $jobId,
                'route' => $route,
            ]);
            return null;
        }

        $decoded = json_decode((string) $responseBody, true);
        return is_array($decoded) ? $decoded : null;
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

        // .env values are resolved by AppConfig, not exported into PHP's process.
        // Preserve inherited runtime variables while forwarding the resolved settings.
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $environment['BEAUTY_ENGINE'] = $this->config->beautyEngine;
        $environment['LKMAGIC_BRIDGE_URL'] = $this->config->lkmagicBridgeUrl;
        $environment['LKMAGIC_EXECUTABLE'] = $this->config->lkmagicExecutable;
        $environment['LKMAGIC_AUTO_START'] = $this->config->lkmagicAutoStart ? '1' : '0';
        $proc = @proc_open($cmd, $descriptors, $pipes, null, $environment);
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

