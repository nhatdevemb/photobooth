<?php

declare(strict_types=1);

namespace Photobooth\Operator\Config;

use Photobooth\Operator\Support\ApiException;

final class AppConfig
{
    public readonly string $appEnv;
    public readonly string $appVersion;
    public readonly string $appTimezone;
    public readonly string $customRoot;
    public readonly string $projectRoot;
    public readonly string $dataDir;
    public readonly string $databasePath;
    public readonly string $operatorBind;
    public readonly string $guestBind;
    public readonly string $shareBaseUrl;
    /** @var list<string> */
    public readonly array $trustedHosts;
    public readonly string $cameraAdapter;
    public readonly string $printerAdapter;
    public readonly string $printerQueue;
    public readonly string $defaultLut;
    public readonly string $defaultTemplate;
    public readonly string $defaultBeautyPreset;
    public readonly int $maxJpegBytes;
    public readonly int $maxImagePixels;
    public readonly int $shareTtlHours;
    public readonly int $sessionRetentionDays;
    public readonly int $auditRetentionDays;
    public readonly int $diskLowBytes;
    public readonly int $authIdleTimeoutSeconds;
    public readonly string $shareSigningKey;
    public readonly int $countdownSeconds;
    public readonly int $captureTimeoutSeconds;
    public readonly int $renderTimeoutSeconds;
    public readonly int $printSubmitTimeoutSeconds;
    public readonly int $printQueryTimeoutSeconds;
    public readonly int $workerHeartbeatSeconds;
    public readonly int $workerStaleSeconds;
    public readonly int $sqliteBusyTimeoutMs;
    public readonly int $mockCaptureDelayMs;
    public readonly int $mockCountdownDelayMs;
    public readonly string $mockFaultScenario;
    public readonly string $mockPrinterScenario;
    public readonly ?int $mockDiskFreeBytes;
    public readonly string $upstreamSha;
    public readonly string $pythonBin;
    public readonly string $dccBaseUrl;
    public readonly string $dccCameraId;
    public readonly string $lkmagicBridgeUrl;
    public readonly string $beautyEngine;

    /**
     * @param array<string, string> $overrides
     */
    public static function fromEnvironment(array $overrides = [], ?string $customRoot = null): self
    {
        $customDir = $customRoot ?? dirname(__DIR__, 2);
        $projectDir = dirname($customDir);
        $envFile = $customDir . '/.env';

        $fileVars = [];
        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }
                $pos = strpos($trimmed, '=');
                if ($pos !== false) {
                    $k = trim(substr($trimmed, 0, $pos));
                    $v = trim(substr($trimmed, $pos + 1));
                    $fileVars[$k] = trim($v, "\"'");
                }
            }
        }

        $get = static function (string $key, string $default = '') use ($overrides, $fileVars): string {
            if (array_key_exists($key, $overrides)) {
                return (string) $overrides[$key];
            }
            $envVal = getenv($key);
            if ($envVal !== false && $envVal !== '') {
                return (string) $envVal;
            }
            if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
                return (string) $_ENV[$key];
            }
            if (array_key_exists($key, $fileVars) && $fileVars[$key] !== '') {
                return $fileVars[$key];
            }
            return $default;
        };

        $dataDir = rtrim($get('DATA_DIR', $customDir . '/runtime-data'), '/');
        $dbPath = $get('DATABASE_PATH', $dataDir . '/db/photobooth.sqlite');

        $upstreamSha = 'a8841409ed8d0db485191269d6c302505b67f64f';
        $upstreamManifestPath = $customDir . '/upstream.json';
        if (is_file($upstreamManifestPath)) {
            $decoded = json_decode((string) file_get_contents($upstreamManifestPath), true);
            if (is_array($decoded) && isset($decoded['upstream']['commit_sha'])) {
                $upstreamSha = (string) $decoded['upstream']['commit_sha'];
            }
        }

        $mockDiskRaw = $get('MOCK_DISK_FREE_BYTES', '');
        $mockDisk = ($mockDiskRaw !== '' && is_numeric($mockDiskRaw)) ? (int) $mockDiskRaw : null;

        $hostsRaw = $get('TRUSTED_HOSTS', '127.0.0.1,localhost,192.168.1.50');
        $trustedHosts = array_values(array_filter(array_map('trim', explode(',', $hostsRaw)), static fn (string $h): bool => $h !== ''));

        $instance = new self(
            appEnv: $get('APP_ENV', 'development'),
            appVersion: $get('APP_VERSION', '1.0.0-mvp'),
            appTimezone: $get('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'),
            customRoot: $customDir,
            projectRoot: $projectDir,
            dataDir: $dataDir,
            databasePath: $dbPath,
            operatorBind: $get('OPERATOR_BIND', '127.0.0.1:8080'),
            guestBind: $get('GUEST_BIND', '0.0.0.0:8081'),
            shareBaseUrl: rtrim($get('SHARE_BASE_URL', 'http://192.168.1.50:8081'), '/'),
            trustedHosts: $trustedHosts,
            cameraAdapter: $get('CAMERA_ADAPTER', 'mock'),
            printerAdapter: $get('PRINTER_ADAPTER', 'mock'),
            printerQueue: $get('PRINTER_QUEUE', 'Canon_SELPHY_CP1500'),
            defaultLut: $get('DEFAULT_LUT', 'original'),
            defaultTemplate: $get('DEFAULT_TEMPLATE', 'vertical-three-v1'),
            defaultBeautyPreset: $get('DEFAULT_BEAUTY_PRESET', 'natural'),
            maxJpegBytes: (int) $get('MAX_JPEG_BYTES', '52428800'),
            maxImagePixels: (int) $get('MAX_IMAGE_PIXELS', '60000000'),
            shareTtlHours: (int) $get('SHARE_TTL_HOURS', '24'),
            sessionRetentionDays: (int) $get('SESSION_RETENTION_DAYS', '7'),
            auditRetentionDays: (int) $get('AUDIT_RETENTION_DAYS', '30'),
            diskLowBytes: (int) $get('DISK_LOW_BYTES', '2147483648'),
            authIdleTimeoutSeconds: (int) $get('AUTH_IDLE_TIMEOUT_SECONDS', '28800'),
            shareSigningKey: $get('SHARE_SIGNING_KEY', 'dev_local_signing_key_photobooth_operator_edition_v1_hmac_sha256'),
            countdownSeconds: (int) $get('COUNTDOWN_SECONDS', '3'),
            captureTimeoutSeconds: (int) $get('CAPTURE_TIMEOUT_SECONDS', '15'),
            renderTimeoutSeconds: (int) $get('RENDER_TIMEOUT_SECONDS', '90'),
            printSubmitTimeoutSeconds: (int) $get('PRINT_SUBMIT_TIMEOUT_SECONDS', '10'),
            printQueryTimeoutSeconds: (int) $get('PRINT_QUERY_TIMEOUT_SECONDS', '5'),
            workerHeartbeatSeconds: (int) $get('WORKER_HEARTBEAT_SECONDS', '2'),
            workerStaleSeconds: (int) $get('WORKER_STALE_SECONDS', '10'),
            sqliteBusyTimeoutMs: (int) $get('SQLITE_BUSY_TIMEOUT_MS', '5000'),
            mockCaptureDelayMs: (int) $get('MOCK_CAPTURE_DELAY_MS', '50'),
            mockCountdownDelayMs: (int) $get('MOCK_COUNTDOWN_DELAY_MS', '30'),
            mockFaultScenario: $get('MOCK_FAULT_SCENARIO', 'none'),
            mockPrinterScenario: $get('MOCK_PRINTER_SCENARIO', 'normal'),
            mockDiskFreeBytes: $mockDisk,
            upstreamSha: $upstreamSha,
            pythonBin: $get('PYTHON_BIN', PHP_OS_FAMILY === 'Windows' ? 'python.exe' : 'python3'),
            dccBaseUrl: rtrim($get('DCC_BASE_URL', 'http://127.0.0.1:5513'), '/'),
            dccCameraId: $get('DCC_CAMERA_ID', ''),
            lkmagicBridgeUrl: rtrim($get('LKMAGIC_BRIDGE_URL', 'http://127.0.0.1:5520'), '/'),
            beautyEngine: strtolower(trim($get('BEAUTY_ENGINE', 'auto')))
        );

        $instance->validate();
        return $instance;
    }

    /**
     * @param list<string> $trustedHosts
     */
    public function __construct(
        string $appEnv,
        string $appVersion,
        string $appTimezone,
        string $customRoot,
        string $projectRoot,
        string $dataDir,
        string $databasePath,
        string $operatorBind,
        string $guestBind,
        string $shareBaseUrl,
        array $trustedHosts,
        string $cameraAdapter,
        string $printerAdapter,
        string $printerQueue,
        string $defaultLut,
        string $defaultTemplate,
        string $defaultBeautyPreset,
        int $maxJpegBytes,
        int $maxImagePixels,
        int $shareTtlHours,
        int $sessionRetentionDays,
        int $auditRetentionDays,
        int $diskLowBytes,
        int $authIdleTimeoutSeconds,
        string $shareSigningKey,
        int $countdownSeconds,
        int $captureTimeoutSeconds,
        int $renderTimeoutSeconds,
        int $printSubmitTimeoutSeconds,
        int $printQueryTimeoutSeconds,
        int $workerHeartbeatSeconds,
        int $workerStaleSeconds,
        int $sqliteBusyTimeoutMs,
        int $mockCaptureDelayMs,
        int $mockCountdownDelayMs,
        string $mockFaultScenario,
        string $mockPrinterScenario,
        ?int $mockDiskFreeBytes,
        string $upstreamSha,
        string $pythonBin = 'python3',
        string $dccBaseUrl = 'http://127.0.0.1:5513',
        string $dccCameraId = '',
        string $lkmagicBridgeUrl = 'http://127.0.0.1:5520',
        string $beautyEngine = 'auto'
    ) {
        $this->appEnv = $appEnv;
        $this->appVersion = $appVersion;
        $this->appTimezone = $appTimezone;
        $this->customRoot = $customRoot;
        $this->projectRoot = $projectRoot;
        $this->dataDir = $dataDir;
        $this->databasePath = $databasePath;
        $this->operatorBind = $operatorBind;
        $this->guestBind = $guestBind;
        $this->shareBaseUrl = $shareBaseUrl;
        $this->trustedHosts = $trustedHosts;
        $this->cameraAdapter = $cameraAdapter;
        $this->printerAdapter = $printerAdapter;
        $this->printerQueue = $printerQueue;
        $this->defaultLut = $defaultLut;
        $this->defaultTemplate = $defaultTemplate;
        $this->defaultBeautyPreset = $defaultBeautyPreset;
        $this->maxJpegBytes = $maxJpegBytes;
        $this->maxImagePixels = $maxImagePixels;
        $this->shareTtlHours = $shareTtlHours;
        $this->sessionRetentionDays = $sessionRetentionDays;
        $this->auditRetentionDays = $auditRetentionDays;
        $this->diskLowBytes = $diskLowBytes;
        $this->authIdleTimeoutSeconds = $authIdleTimeoutSeconds;
        $this->shareSigningKey = $shareSigningKey;
        $this->countdownSeconds = $countdownSeconds;
        $this->captureTimeoutSeconds = $captureTimeoutSeconds;
        $this->renderTimeoutSeconds = $renderTimeoutSeconds;
        $this->printSubmitTimeoutSeconds = $printSubmitTimeoutSeconds;
        $this->printQueryTimeoutSeconds = $printQueryTimeoutSeconds;
        $this->workerHeartbeatSeconds = $workerHeartbeatSeconds;
        $this->workerStaleSeconds = $workerStaleSeconds;
        $this->sqliteBusyTimeoutMs = $sqliteBusyTimeoutMs;
        $this->mockCaptureDelayMs = $mockCaptureDelayMs;
        $this->mockCountdownDelayMs = $mockCountdownDelayMs;
        $this->mockFaultScenario = $mockFaultScenario;
        $this->mockPrinterScenario = $mockPrinterScenario;
        $this->mockDiskFreeBytes = $mockDiskFreeBytes;
        $this->upstreamSha = $upstreamSha;
        $this->pythonBin = $pythonBin;
        $this->dccBaseUrl = $dccBaseUrl;
        $this->dccCameraId = $dccCameraId;
        $this->lkmagicBridgeUrl = $lkmagicBridgeUrl;
        $this->beautyEngine = $beautyEngine;
    }

    public function validate(): void
    {
        if (!in_array($this->cameraAdapter, ['mock', 'hardware', 'browser', 'digicamcontrol'], true)) {
            throw new ApiException(
                'INVALID_ADAPTER_CONFIG',
                "Cấu hình CAMERA_ADAPTER không hợp lệ: '{$this->cameraAdapter}' (mock, hardware, browser hoặc digicamcontrol).",
                503
            );
        }

        $dcc = parse_url($this->dccBaseUrl);
        if (!is_array($dcc) || ($dcc['scheme'] ?? '') !== 'http'
            || !in_array($dcc['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || isset($dcc['user'], $dcc['pass']) || isset($dcc['user']) || isset($dcc['query'])
            || isset($dcc['fragment']) || !in_array($dcc['path'] ?? '', ['', '/'], true)) {
            throw new ApiException('INVALID_DCC_CONFIG', 'DCC_BASE_URL phải là HTTP loopback, ví dụ http://127.0.0.1:5513.', 503);
        }

        if (!in_array($this->printerAdapter, ['mock', 'hardware'], true)) {
            throw new ApiException(
                'INVALID_ADAPTER_CONFIG',
                "Cấu hình PRINTER_ADAPTER không hợp lệ: '{$this->printerAdapter}' (chỉ nhận 'mock' hoặc 'hardware').",
                503
            );
        }

        if (!in_array($this->defaultBeautyPreset, ['off', 'natural', 'soft', 'korean-glow'], true)) {
            throw new ApiException(
                'INVALID_BEAUTY_CONFIG',
                "Cấu hình DEFAULT_BEAUTY_PRESET không hợp lệ: '{$this->defaultBeautyPreset}'.",
                503
            );
        }

        if (!in_array($this->beautyEngine, ['auto', 'lkmagic', 'mediapipe'], true)) {
            throw new ApiException(
                'INVALID_BEAUTY_CONFIG',
                "Cấu hình BEAUTY_ENGINE không hợp lệ: '{$this->beautyEngine}' (chỉ nhận 'auto', 'lkmagic' hoặc 'mediapipe').",
                503
            );
        }

        $lk = parse_url($this->lkmagicBridgeUrl);
        if (!is_array($lk) || ($lk['scheme'] ?? '') !== 'http'
            || !in_array($lk['host'] ?? '', ['127.0.0.1', '[::1]', 'localhost'], true)
            || isset($lk['user'], $lk['pass']) || isset($lk['user']) || isset($lk['query'])
            || isset($lk['fragment']) || !in_array($lk['path'] ?? '', ['', '/'], true)) {
            throw new ApiException('INVALID_BEAUTY_CONFIG', 'LKMAGIC_BRIDGE_URL phải là HTTP loopback, ví dụ http://127.0.0.1:5520.', 503);
        }

        self::validateShareBaseUrl($this->shareBaseUrl);

        if (strlen($this->shareSigningKey) < 16 || $this->shareSigningKey === 'CHANGE_ME_GENERATE_WITH_OPENSSL_RAND_HEX_32') {
            throw new ApiException(
                'INVALID_SIGNING_KEY',
                'SHARE_SIGNING_KEY chưa được cấu hình hợp lệ (cần tối thiểu 16 ký tự bí mật).',
                503
            );
        }
    }

    public static function validateShareBaseUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ApiException(
                'INVALID_SHARE_BASE_URL',
                'SHARE_BASE_URL phải là URL đầy đủ (http:// hoặc https://) có địa chỉ LAN hợp lệ.',
                503
            );
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ApiException(
                'INVALID_SHARE_BASE_URL',
                'SHARE_BASE_URL chỉ hỗ trợ giao thức http hoặc https.',
                503
            );
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if (
            $host === ''
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '::1'
            || $host === '0:0:0:0:0:0:0:1'
            || $host === '0.0.0.0'
            || $host === '::'
            || str_starts_with($host, '127.')
        ) {
            throw new ApiException(
                'INVALID_SHARE_BASE_URL',
                "SHARE_BASE_URL không được dùng địa chỉ loopback hoặc wildcard ('{$host}'). Vui lòng cấu hình IP/hostname LAN.",
                503
            );
        }
    }

    public function isMockMode(): bool
    {
        return $this->cameraAdapter === 'mock' || $this->printerAdapter === 'mock';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPresetsMap(): array
    {
        $manifestPath = $this->customRoot . '/presets/manifest.json';
        if (!is_file($manifestPath)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($decoded) || !isset($decoded['presets']) || !is_array($decoded['presets'])) {
            return [];
        }
        $map = [];
        foreach ($decoded['presets'] as $preset) {
            if (is_array($preset) && isset($preset['id'])) {
                $map[(string) $preset['id']] = $preset;
            }
        }
        return $map;
    }

    public function getTemplatePath(string $templateId): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $templateId)) {
            throw new ApiException('INVALID_TEMPLATE', 'Mã template không hợp lệ.', 422);
        }
        $path = $this->customRoot . '/templates/' . $templateId . '.json';
        if (!is_file($path)) {
            throw new ApiException('INVALID_TEMPLATE', "Không tìm thấy template '{$templateId}'.", 422);
        }
        return $path;
    }
}

