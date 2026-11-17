<?php

declare(strict_types=1);

namespace Photobooth\Operator\Camera;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Support\ApiException;

final class DigiCamControlCameraAdapter implements CameraAdapterInterface
{
    private DigiCamControlClient $client;

    public function __construct(private AppConfig $config, private Renderer $renderer, ?DigiCamControlClient $client = null)
    {
        $this->client = $client ?? new DigiCamControlClient($config);
    }

    public function getMode(): string { return 'digicamcontrol'; }

    private function lock(int $operation = LOCK_EX)
    {
        $directory = $this->config->dataDir . '/dcc';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new ApiException('STORAGE_ERROR', 'Không tạo được thư mục camera.', 503);
        }
        $handle = fopen($directory . '/camera.lock', 'c+');
        if ($handle === false || !flock($handle, $operation | LOCK_NB)) {
            if (is_resource($handle)) { fclose($handle); }
            throw new ApiException('CAMERA_BUSY', 'Máy ảnh đang chụp hoặc đổi live view. Vui lòng đợi.', 409);
        }
        return $handle;
    }

    public function startPreview(): array
    {
        $lock = $this->lock();
        try {
            $this->client->preview(true, $this->client->selectCamera());
            return ['status' => 'started', 'frame_urls' => ['/custom/api/camera/preview.jpg']];
        } finally { fclose($lock); }
    }

    public function stopPreview(): void
    {
        $lock = $this->lock();
        try { $this->client->preview(false, $this->client->selectCamera()); }
        finally { fclose($lock); }
    }

    public function previewFrame(): string
    {
        $lock = $this->lock(LOCK_SH);
        try {
            $camera = $this->client->selectCamera();
            if ($this->client->slc('get', 'camera') !== $camera) {
                throw new ApiException('DCC_CAMERA_CHANGED', 'Camera đang chọn trong digiCamControl đã thay đổi. Bật lại live view.', 409);
            }
            return $this->client->frame();
        } finally { fclose($lock); }
    }

    public function disconnect(): void { $this->stopPreview(); }

    public function capture(string $destinationDir, int $slot, int $version, int $deadlineUnix, ?string $faultScenario = null): array
    {
        $lock = $this->lock();
        try {
            $directory = realpath($destinationDir);
            $dataRoot = realpath($this->config->dataDir);
            if ($directory === false || $dataRoot === false || !str_starts_with(str_replace('\\', '/', $directory), rtrim(str_replace('\\', '/', $dataRoot), '/') . '/')) {
                throw new ApiException('STORAGE_ERROR', 'Thư mục chụp phải nằm trong DATA_DIR.', 500);
            }
            $key = hash('sha256', $directory);
            $receiptPath = $this->config->dataDir . '/dcc/' . $key . '.json';
            $target = $directory . '/capture.jpg';
            if (is_file($receiptPath)) {
                $receipt = json_decode((string) file_get_contents($receiptPath), true);
                if (($receipt['state'] ?? '') === 'COMPLETED' && is_file($target)) {
                    $meta = $this->renderer->validateJpeg($target);
                    if (hash_equals((string) ($receipt['sha256'] ?? ''), $meta['sha256'])) {
                        return ['temp_path' => $target] + $meta;
                    }
                }
                throw new ApiException('DCC_CAPTURE_UNCERTAIN', 'Yêu cầu đã gửi hoặc bị gián đoạn. Không tự chụp lại; hãy tạo yêu cầu chụp mới.', 409);
            }
            $camera = $this->client->selectCamera();
            $incoming = $this->config->dataDir . '/dcc/incoming/' . $key;
            if (!is_dir($incoming) && !mkdir($incoming, 0750, true) && !is_dir($incoming)) {
                throw new ApiException('STORAGE_ERROR', 'Không tạo được thư mục nhận JPEG.', 503);
            }
            $incoming = (string) realpath($incoming);
            $stem = 'pb-' . substr($key, 0, 24);
            // Verify an active session is selected, select the target camera, and bind the
            // session output folder & filename template using DCC's official SLC keys.
            $session = $this->client->slc('get', 'session', '', $camera);
            if ($session === '' || $session === 'OK') { throw new ApiException('DCC_SESSION_MISMATCH', 'Chưa chọn session digiCamControl.', 409); }
            $this->client->command('set', 'camera', $camera, $camera);
            $this->client->command('set', 'session', $session, $camera);
            $this->client->command('set', 'session.folder', $incoming, $camera);
            $this->client->command('set', 'session.filenametemplate', $stem, $camera);
            // Verify both settings; successful HTTP alone does not prove DCC accepted them.
            $actualFolder = str_replace('\\', '/', $this->client->slc('get', 'session.folder', '', $camera));
            if (strcasecmp(rtrim($actualFolder, '/'), rtrim(str_replace('\\', '/', $incoming), '/')) !== 0
                || $this->client->slc('get', 'session.filenametemplate', '', $camera) !== $stem) {
                throw new ApiException('DCC_SESSION_MISMATCH', 'DCC không giữ đúng thư mục/tên ảnh dành cho job. Kiểm tra session trước khi chụp.', 409);
            }
            $receipt = ['state' => 'SUBMITTING', 'camera' => $camera, 'slot' => $slot, 'version' => $version];
            $this->writeReceipt($receiptPath, $receipt);
            // SLC capture uses autofocus (never CaptureNoAf). A timeout can mean the shutter fired.
            $remaining = $deadlineUnix - microtime(true);
            if ($remaining <= 0) { throw new ApiException('CAPTURE_TIMEOUT', 'Hết thời gian chụp.', 504); }
            $this->client->command('capture', '', '', $camera, $remaining);
            $lastSize = -1;
            $stableSince = microtime(true);
            while (microtime(true) < $deadlineUnix) {
                $files = array_values(array_filter(glob($incoming . '/*') ?: [], static fn (string $p): bool => is_file($p) && !is_link($p) && preg_match('/\.jpe?g$/iD', $p) === 1));
                if (count($files) > 1 || (count($files) === 1 && !str_starts_with(basename($files[0]), $stem))) {
                    throw new ApiException('DCC_AMBIGUOUS_CAPTURE', 'Có ảnh ngoài luồng hoặc nhiều JPEG cho cùng job; không tự chọn ảnh.', 409);
                }
                if (count($files) === 1) {
                    clearstatcache(true, $files[0]);
                    $size = filesize($files[0]);
                    if ($size !== $lastSize) { $lastSize = $size; $stableSince = microtime(true); }
                    if ($size > $this->config->maxJpegBytes) { throw new ApiException('IMAGE_TOO_LARGE', 'JPEG camera quá lớn.', 422); }
                    if ($size > 0 && microtime(true) - $stableSince >= 0.4) {
                        $last = $this->client->slc('get', 'lastcaptured', '', $camera, min(2, max(0.1, $deadlineUnix - microtime(true))));
                        if ($last === basename($files[0])) {
                            $source = fopen($files[0], 'rb');
                            if ($source !== false && flock($source, LOCK_SH | LOCK_NB)) {
                                try {
                                    if (!copy($files[0], $target . '.tmp')) { throw new ApiException('STORAGE_ERROR', 'Không sao chép được JPEG.', 503); }
                                    $meta = $this->renderer->validateJpeg($target . '.tmp');
                                    if (!hash_equals(hash_file('sha256', $files[0]), $meta['sha256'])) { throw new ApiException('HASH_MISMATCH', 'JPEG đang thay đổi.', 409); }
                                    if (!rename($target . '.tmp', $target)) { throw new ApiException('STORAGE_ERROR', 'Không lưu được JPEG.', 503); }
                                } finally { fclose($source); }
                                $this->writeReceipt($receiptPath, $receipt + ['sha256' => $meta['sha256']], 'COMPLETED');
                                return ['temp_path' => $target] + $meta;
                            }
                            if (is_resource($source)) { fclose($source); }
                        }
                    }
                }
                usleep(100000);
            }
            throw new ApiException('CAPTURE_TIMEOUT', 'Chưa nhận đúng JPEG. Không tự gửi lại lệnh chụp.', 504);
        } finally { fclose($lock); }
    }

    private function writeReceipt(string $path, array $receipt, ?string $state = null): void
    {
        if ($state !== null) { $receipt['state'] = $state; }
        $handle = fopen($path . '.tmp', 'wb');
        if ($handle === false) { throw new ApiException('STORAGE_ERROR', 'Không ghi được biên nhận camera.', 503); }
        try {
            $bytes = json_encode($receipt, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ApiException('STORAGE_ERROR', 'Không đồng bộ được biên nhận camera.', 503);
            }
        } finally { fclose($handle); }
        if (!rename($path . '.tmp', $path)) { throw new ApiException('STORAGE_ERROR', 'Không lưu được biên nhận camera.', 503); }
    }

    public function health(): array
    {
        try {
            $id = $this->client->selectCamera();
            return ['adapter' => 'DigiCamControlCameraAdapter', 'mode' => $this->getMode(), 'camera_state' => 'connected', 'preview_state' => 'available', 'model' => 'Canon qua digiCamControl · ' . $id, 'details' => 'JPEG qua USB; live view và capture do digiCamControl quản lý.'];
        } catch (ApiException $e) {
            return ['adapter' => 'DigiCamControlCameraAdapter', 'mode' => $this->getMode(), 'camera_state' => 'error', 'preview_state' => 'unavailable', 'model' => 'Canon qua digiCamControl', 'details' => $e->getMessage()];
        }
    }
}
