<?php

declare(strict_types=1);

namespace Photobooth\Operator\Camera;

use Closure;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Support\ApiException;

/** Fixed local DCC commands only. Capture requests are deliberately never retried. */
final class DigiCamControlClient
{
    private ?Closure $transport;

    public function __construct(private AppConfig $config, ?callable $transport = null)
    {
        $this->transport = $transport === null ? null : Closure::fromCallable($transport);
    }

    public function slc(string $command, string $parameter = '', string $value = '', string $camera = '', float $timeout = 3): string
    {
        return trim($this->request('/', ['slc' => $command, 'param1' => $parameter, 'param2' => $value, 'camera' => $camera], $timeout));
    }

    public function command(string $command, string $parameter = '', string $value = '', string $camera = '', float $timeout = 3): void
    {
        $result = $this->slc($command, $parameter, $value, $camera, $timeout);
        if (strcasecmp($result, 'OK') !== 0) {
            throw new ApiException('DCC_COMMAND_FAILED', 'digiCamControl: ' . mb_substr(strip_tags($result), 0, 250), 503);
        }
    }

    public function selectCamera(): string
    {
        $raw = $this->slc('list', 'cameras');
        $cameras = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw) ?: [])));
        // Canon serial numbers contain digits. Reject a DCC exception/HTML/"OK" as an identity.
        $cameras = array_values(array_filter($cameras, static fn (string $v): bool => preg_match('/^[A-Za-z0-9_-]*[0-9][A-Za-z0-9_-]*$/D', $v) === 1));
        $selected = $this->config->dccCameraId;
        if ($selected === '') {
            if (count($cameras) !== 1) {
                throw new ApiException('DCC_CAMERA_SELECTION_REQUIRED', 'Cần đúng một máy ảnh trong digiCamControl hoặc cấu hình DCC_CAMERA_ID bằng serial.', 503);
            }
            $selected = $cameras[0];
        }
        if (!in_array($selected, $cameras, true)) {
            throw new ApiException('DEVICE_UNAVAILABLE', 'Máy ảnh đã chọn chưa kết nối với digiCamControl.', 503);
        }
        return $selected;
    }

    public function preview(bool $start, string $camera): void
    {
        // DCC's liveview.jpg serves the globally selected camera, unlike SLC capture.
        $this->command('set', 'camera', $camera, $camera);
        $response = trim($this->request('/', ['CMD' => $start ? 'LiveViewWnd_Show' : 'LiveViewWnd_Hide'], 3));
        // The legacy CMD endpoint responds with a web page; SLC selection above is checked.
        if ($response === '') {
            throw new ApiException('DCC_COMMAND_FAILED', 'digiCamControl không phản hồi lệnh live view.', 503);
        }
    }

    public function frame(): string
    {
        $bytes = $this->request('/liveview.jpg', [], 2, 5 * 1024 * 1024);
        if (!str_starts_with($bytes, "\xff\xd8") || !str_ends_with($bytes, "\xff\xd9")) {
            throw new ApiException('DCC_PREVIEW_UNAVAILABLE', 'Chưa có live view JPEG. Kiểm tra R10 và cửa sổ live view digiCamControl.', 503);
        }
        return $bytes;
    }

    private function request(string $path, array $query, float $timeout, int $limit = 1048576): string
    {
        $url = $this->config->dccBaseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        if ($this->transport !== null) {
            return ($this->transport)($url, $timeout);
        }
        if (!function_exists('curl_init')) {
            throw new ApiException('DCC_UNAVAILABLE', 'PHP cần extension curl để kết nối digiCamControl.', 503);
        }
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT_MS => min(1000, max(1, (int) ($timeout * 1000))),
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200) {
            throw new ApiException('DCC_UNAVAILABLE', 'Không nhận được phản hồi digiCamControl. Bật Webserver và Allow interaction tại cổng local đã cấu hình.', 503);
        }
        return $body;
    }
}
