<?php

declare(strict_types=1);

namespace Photobooth\Operator\Camera;

use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Support\ApiException;

/** The authenticated capture request stages one JPEG; the worker validates it. */
final class BrowserCameraAdapter implements CameraAdapterInterface
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    public function __construct(private Renderer $renderer)
    {
    }

    public function getMode(): string
    {
        return 'browser';
    }

    public function startPreview(): array
    {
        return ['status' => 'client_managed', 'frame_urls' => []];
    }

    public function stopPreview(): void
    {
    }

    public function disconnect(): void
    {
    }

    public function capture(string $destinationDir, int $slot, int $version, int $deadlineUnix, ?string $faultScenario = null): array
    {
        $path = $destinationDir . '/browser.jpg';
        if (!is_file($path)) {
            throw new ApiException('CAMERA_FRAME_MISSING', 'Không tìm thấy ảnh từ camera trình duyệt. Hãy chụp lại.', 422);
        }
        return ['temp_path' => $path] + $this->renderer->validateJpeg($path);
    }

    public function health(): array
    {
        return [
            'adapter' => 'BrowserCameraAdapter',
            'mode' => 'browser',
            'camera_state' => 'client_managed',
            'preview_state' => 'client_managed',
            'model' => 'Camera laptop / điện thoại',
            'details' => 'Quyền camera và kết nối được kiểm tra trên thiết bị mở giao diện, tại bước Chụp ảnh.',
        ];
    }
}
