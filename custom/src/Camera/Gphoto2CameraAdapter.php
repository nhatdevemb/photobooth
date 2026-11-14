<?php

declare(strict_types=1);

namespace Photobooth\Operator\Camera;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Support\ApiException;

/**
 * Hardware camera adapter via gphoto2 (Milestone H1 target).
 * Never silently falls back to mock when hardware is missing or unavailable.
 */
final class Gphoto2CameraAdapter implements CameraAdapterInterface
{
    private AppConfig $config;
    private Renderer $renderer;

    public function __construct(AppConfig $config, Renderer $renderer)
    {
        $this->config = $config;
        $this->renderer = $renderer;
    }

    public function getMode(): string
    {
        return 'hardware';
    }

    public function startPreview(): array
    {
        $health = $this->health();
        if ($health['camera_state'] !== 'connected') {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Không tìm thấy máy ảnh phần cứng (Canon R50). Không tự động chuyển về chế độ mô phỏng.',
                503
            );
        }
        return [
            'status' => 'available',
            'frame_urls' => [],
        ];
    }

    public function stopPreview(): void
    {
    }

    public function disconnect(): void
    {
    }

    public function capture(
        string $destinationDir,
        int $slot,
        int $version,
        int $deadlineUnix,
        ?string $faultScenario = null
    ): array {
        $health = $this->health();
        if ($health['camera_state'] !== 'connected') {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Máy ảnh phần cứng chưa sẵn sàng hoặc mất kết nối USB.',
                503
            );
        }

        $tempPath = rtrim($destinationDir, '/') . '/capture.jpg';
        $cmd = ['gphoto2', '--capture-image-and-download', '--force-overwrite', '--filename=' . $tempPath];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new ApiException('DEVICE_UNAVAILABLE', 'Không thể khởi chạy tiến trình gphoto2.', 503);
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0 || !is_file($tempPath)) {
            throw new ApiException('DEVICE_UNAVAILABLE', 'Chụp ảnh qua gphoto2 thất bại.', 503);
        }

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
    }

    public function health(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(['gphoto2', '--auto-detect'], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [
                'adapter' => 'Gphoto2CameraAdapter',
                'mode' => 'hardware',
                'camera_state' => 'error',
                'preview_state' => 'error',
                'model' => 'Hardware Camera (gphoto2 missing)',
                'details' => 'Chưa cài đặt gphoto2 hoặc không truy cập được USB.',
            ];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode === 0 && preg_match('/usb:\d+,\d+/i', $stdout) === 1) {
            return [
                'adapter' => 'Gphoto2CameraAdapter',
                'mode' => 'hardware',
                'camera_state' => 'connected',
                'preview_state' => 'available',
                'model' => 'Hardware USB Camera',
                'details' => trim($stdout),
            ];
        }

        return [
            'adapter' => 'Gphoto2CameraAdapter',
            'mode' => 'hardware',
            'camera_state' => 'error',
            'preview_state' => 'error',
            'model' => 'Hardware Camera Not Detected',
            'details' => 'Không phát hiện máy ảnh qua gphoto2 --auto-detect.',
        ];
    }
}

