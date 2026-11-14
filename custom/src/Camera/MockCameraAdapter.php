<?php

declare(strict_types=1);

namespace Photobooth\Operator\Camera;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;

final class MockCameraAdapter implements CameraAdapterInterface
{
    private AppConfig $config;
    private Renderer $renderer;
    private bool $previewActive = true;
    private bool $disconnected = false;

    public function __construct(AppConfig $config, Renderer $renderer)
    {
        $this->config = $config;
        $this->renderer = $renderer;
    }

    public function getMode(): string
    {
        return 'mock';
    }

    public function startPreview(): array
    {
        if ($this->isDisconnectedScenario()) {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Camera mô phỏng đang ở trạng thái mất kết nối.',
                503
            );
        }
        $this->previewActive = true;
        return [
            'status' => 'available',
            'frame_urls' => [
                '/custom/assets/mock-preview/frame-1.jpg',
                '/custom/assets/mock-preview/frame-2.jpg',
                '/custom/assets/mock-preview/frame-3.jpg',
            ],
        ];
    }

    public function stopPreview(): void
    {
        $this->previewActive = false;
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
        $this->previewActive = false;
    }

    public function capture(
        string $destinationDir,
        int $slot,
        int $version,
        int $deadlineUnix,
        ?string $faultScenario = null
    ): array {
        $scenario = $faultScenario ?? $this->config->mockFaultScenario;

        if ($this->disconnected || $scenario === 'disconnect') {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Mất kết nối với máy ảnh (USB/PTP disconnect). Vui lòng kiểm tra cáp và nguồn máy ảnh.',
                503
            );
        }

        if ($scenario === 'timeout') {
            throw new ApiException(
                'CAPTURE_TIMEOUT',
                'Quá thời gian chờ máy ảnh chụp ảnh (capture timeout). Không tự động chụp lại.',
                504
            );
        }

        if ($scenario === 'storage_fail') {
            throw new ApiException(
                'STORAGE_ERROR',
                'Lỗi lưu tệp ảnh từ máy ảnh vào bộ nhớ tạm.',
                500
            );
        }

        if (Clock::nowUnix() > $deadlineUnix) {
            throw new ApiException(
                'CAPTURE_TIMEOUT',
                'Đã vượt quá thời hạn thực thi lệnh chụp ảnh.',
                504
            );
        }

        $this->stopPreview();

        try {
            if ($this->config->mockCaptureDelayMs > 0) {
                usleep($this->config->mockCaptureDelayMs * 1000);
            }

            $fixturesDir = $this->config->customRoot . '/tests/fixtures';
            $fixtureFile = match ($scenario) {
                'corrupt_jpeg' => $fixturesDir . '/corrupt-truncated.jpg',
                'corrupt_icc' => $fixturesDir . '/corrupt-icc.jpg',
                'no_icc' => $fixturesDir . '/no-icc-profile.jpg',
                'exif_6' => $fixturesDir . '/exif-orientation-6.jpg',
                default => $this->resolveDeterministicFixture($fixturesDir, $slot, $version),
            };

            if (!is_file($fixtureFile)) {
                throw new ApiException(
                    'DEVICE_UNAVAILABLE',
                    'Không tìm thấy tệp ảnh mẫu của bộ mô phỏng camera.',
                    503
                );
            }

            if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0750, true) && !is_dir($destinationDir)) {
                throw new ApiException('STORAGE_ERROR', 'Không thể tạo thư mục pending cho lần chụp.', 500);
            }

            $tempPath = rtrim($destinationDir, '/') . '/capture.jpg';
            if (!@copy($fixtureFile, $tempPath)) {
                throw new ApiException('STORAGE_ERROR', 'Không thể ghi tệp ảnh từ camera mô phỏng.', 500);
            }

            // Validate JPEG format, dimensions, ICC, and SHA-256 before returning
            $meta = $this->renderer->validateJpeg($tempPath);

            return [
                'temp_path' => $tempPath,
                'sha256' => $meta['sha256'],
                'width' => $meta['width'],
                'height' => $meta['height'],
                'bytes' => $meta['bytes'],
                'exif_orientation' => $meta['exif_orientation'],
                'icc_status' => $meta['icc_status'],
            ];
        } finally {
            if (!$this->isDisconnectedScenario()) {
                $this->previewActive = true;
            }
        }
    }

    public function health(): array
    {
        if ($this->isDisconnectedScenario()) {
            return [
                'adapter' => 'MockCameraAdapter',
                'mode' => 'mock',
                'camera_state' => 'error',
                'preview_state' => 'error',
                'model' => 'Simulated Canon EOS R50 (MOCK - Disconnected)',
                'details' => 'Máy ảnh mô phỏng đang ở kịch bản mất kết nối.',
            ];
        }

        return [
            'adapter' => 'MockCameraAdapter',
            'mode' => 'mock',
            'camera_state' => 'connected',
            'preview_state' => $this->previewActive ? 'available' : 'paused',
            'model' => 'Simulated Canon EOS R50 (MOCK)',
            'details' => 'Bộ mô phỏng camera sẵn sàng (deterministic fixtures).',
        ];
    }

    private function isDisconnectedScenario(): bool
    {
        return $this->disconnected || $this->config->mockFaultScenario === 'disconnect';
    }

    private function resolveDeterministicFixture(string $fixturesDir, int $slot, int $version): string
    {
        $slotClamped = max(1, min(4, $slot));
        // Cycle deterministically through v1..v4 fixtures, and if version > 4 embed a deterministic comment
        $verIndex = (($version - 1) % 4) + 1;
        return sprintf('%s/slot-%d-v%d.jpg', $fixturesDir, $slotClamped, $verIndex);
    }
}

