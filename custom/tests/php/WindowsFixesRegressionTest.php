<?php

declare(strict_types=1);

namespace Photobooth\Operator\Tests;

use PHPUnit\Framework\TestCase;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Container;
use Photobooth\Operator\Http\HttpKernel;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Uuid;

final class WindowsFixesRegressionTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pb-win-fixes-' . Uuid::v4();
        mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $c = new Container($this->baseEnv());
            $c->assetStore->removeDirectoryRecursive($this->tempDir);
        }
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function baseEnv(array $overrides = []): array
    {
        return array_merge([
            'APP_ENV' => 'testing',
            'DATA_DIR' => $this->tempDir,
            'DATABASE_PATH' => $this->tempDir . '/db/photobooth.sqlite',
            'CAMERA_ADAPTER' => 'mock',
            'PRINTER_ADAPTER' => 'mock',
            'MOCK_CAPTURE_DELAY_MS' => '5',
            'MOCK_COUNTDOWN_DELAY_MS' => '0',
            'SHARE_BASE_URL' => 'http://192.168.1.50:8081',
            'TRUSTED_HOSTS' => '127.0.0.1,localhost,192.168.1.50',
            'SHARE_SIGNING_KEY' => 'test_signing_key_windows_fixes_regression_0123456789',
        ], $overrides);
    }

    public function testInvalidSlotMessagesReflectFourSlotSupportAndPinnedLayout(): void
    {
        $container = new Container($this->baseEnv());
        $user = $container->authService->createUser('op_slot', 'OperatorPass123!', 'operator');
        $session = $container->sessionService->createSession($user['id'], Uuid::v4())['body'];
        $sid = (string) $session['id'];

        try {
            $container->sessionService->enqueueSlotCaptureOrRetake($sid, $user['id'], 5, 'capture', 1, Uuid::v4());
            self::fail('Expected INVALID_SLOT for slot 5');
        } catch (ApiException $e) {
            self::assertSame('INVALID_SLOT', $e->getErrorCode());
            self::assertStringContainsString('4', $e->getMessage());
        }

        // Single-photo layout rejects slot 2 with explicit layout capture_count
        $cfg = $container->sessionService->configure($sid, $user['id'], 1, ['layout_id' => 'single'], Uuid::v4())['body'];
        try {
            $container->sessionService->enqueueSlotCaptureOrRetake($sid, $user['id'], 2, 'capture', (int) $cfg['revision'], Uuid::v4());
            self::fail('Expected INVALID_SLOT for slot 2 on single layout');
        } catch (ApiException $e) {
            self::assertSame('INVALID_SLOT', $e->getErrorCode());
            self::assertStringContainsString('1 ảnh', $e->getMessage());
        }
    }

    public function testBatchListSessionsMatchesSingleSessionViewExactStructure(): void
    {
        $container = new Container($this->baseEnv());
        $admin = $container->authService->createUser('admin_batch', 'AdminPass123!', 'admin');

        $created = $container->sessionService->createSession($admin['id'], Uuid::v4())['body'];
        $sid = (string) $created['id'];
        $cfg = $container->sessionService->configure($sid, $admin['id'], (int) $created['revision'], ['layout_id' => 'single', 'beauty_preset' => 'off'], Uuid::v4())['body'];
        $container->sessionService->enqueueSlotCaptureOrRetake($sid, $admin['id'], 1, 'capture', (int) $cfg['revision'], Uuid::v4());
        $container->workerRunner->drainAll();
        $cap = $container->sessionService->getSession($sid);
        $container->sessionService->enqueueRender($sid, $admin['id'], (int) $cap['revision'], (string) $cap['selected_lut'], (string) $cap['template_id'], Uuid::v4());
        $container->workerRunner->drainAll();
        $rnd = $container->sessionService->getSession($sid);
        $fin = $container->sessionService->finalizeSession($sid, $admin['id'], (int) $rnd['revision'], (string) $rnd['current_render']['id'], Uuid::v4())['body'];
        $shr = $container->sessionService->createShareToken($sid, $admin['id'], (int) $fin['revision'], (string) $fin['final_asset_id'], Uuid::v4())['body'];
        $prt = $container->printService->createPrintRequest($sid, $admin['id'], (int) $shr['revision'], (string) $fin['final_asset_id'], 1, 'print', Uuid::v4())['body'];
        $container->workerRunner->drainAll();
        $container->sessionService->closeSession($sid, $admin['id'], (int) $prt['revision'], Uuid::v4());

        for ($i = 0; $i < 10; $i++) {
            $s = $container->sessionService->createSession($admin['id'], Uuid::v4())['body'];
            $container->sessionService->cancelSession((string) $s['id'], $admin['id'], (int) $s['revision'], Uuid::v4());
        }

        $listed = $container->sessionService->listSessions(null, 20);
        self::assertCount(11, $listed['items']);
        foreach ($listed['items'] as $item) {
            self::assertSame($container->sessionService->getSession((string) $item['id']), $item);
        }
    }

    public function testGuestRateLimiterBlocksFloodingIpWithoutBlockingOtherIpsAndPreservesIntegrityCheck(): void
    {
        $container = new Container($this->baseEnv([
            'GUEST_RATE_LIMIT_WINDOW_SECONDS' => '60',
            'GUEST_RATE_LIMIT_MAX_REQUESTS' => '6',
            'GUEST_RATE_LIMIT_MAX_INVALID' => '3',
        ]));
        $admin = $container->authService->createUser('admin_qr', 'AdminPass123!', 'admin');
        $kernel = new HttpKernel($container);

        // Create finalized session + valid QR share token
        $s = $container->sessionService->createSession($admin['id'], Uuid::v4())['body'];
        $sid = (string) $s['id'];
        $cfg = $container->sessionService->configure($sid, $admin['id'], 1, ['layout_id' => 'single', 'beauty_preset' => 'off'], Uuid::v4())['body'];
        $container->sessionService->enqueueSlotCaptureOrRetake($sid, $admin['id'], 1, 'capture', (int) $cfg['revision'], Uuid::v4());
        $container->workerRunner->drainAll();
        $cap = $container->sessionService->getSession($sid);
        $container->sessionService->enqueueRender($sid, $admin['id'], (int) $cap['revision'], (string) $cap['selected_lut'], (string) $cap['template_id'], Uuid::v4());
        $container->workerRunner->drainAll();
        $rnd = $container->sessionService->getSession($sid);
        $fin = $container->sessionService->finalizeSession($sid, $admin['id'], (int) $rnd['revision'], (string) $rnd['current_render']['id'], Uuid::v4())['body'];
        $share = $container->sessionService->createShareToken($sid, $admin['id'], (int) $fin['revision'], (string) $fin['final_asset_id'], Uuid::v4())['body'];
        $path = (string) parse_url((string) $share['download_url'], PHP_URL_PATH);

        // Scanner IP hits 3 invalid tokens -> 4th request gets 429 + Retry-After
        for ($i = 0; $i < 3; $i++) {
            $r = $kernel->handle(['method' => 'GET', 'path' => '/share/bad-' . $i, 'ip' => '192.168.1.201', 'listener_mode' => 'guest']);
            self::assertSame(404, $r['status']);
        }
        $blockedHtml = $kernel->handle(['method' => 'GET', 'path' => '/share/bad-4', 'ip' => '192.168.1.201', 'listener_mode' => 'guest']);
        self::assertSame(429, $blockedHtml['status']);
        self::assertArrayHasKey('Retry-After', $blockedHtml['headers']);

        $blockedDl = $kernel->handle(['method' => 'GET', 'path' => $path, 'ip' => '192.168.1.201', 'listener_mode' => 'guest']);
        self::assertSame(429, $blockedDl['status']);
        self::assertSame('GUEST_RATE_LIMITED', json_decode($blockedDl['body'], true)['error']['code']);

        // Legitimate guest IP 192.168.1.202 still downloads cleanly
        $okDl = $kernel->handle(['method' => 'GET', 'path' => $path, 'ip' => '192.168.1.202', 'listener_mode' => 'guest']);
        self::assertSame(200, $okDl['status']);

        // IT-11 invariant: corrupting file on disk still returns 404 on guest download
        $absFile = (string) $okDl['file_path'];
        file_put_contents($absFile, file_get_contents($absFile) . 'TAMPER');
        $corruptDl = $kernel->handle(['method' => 'GET', 'path' => $path, 'ip' => '192.168.1.202', 'listener_mode' => 'guest']);
        self::assertSame(404, $corruptDl['status']);
    }

    public function testRendererAutomaticallyFallsBackToCliWhenDaemonUnreachable(): void
    {
        // Point IMAGE_DAEMON_URL at an unbound local port; Renderer must fall back cleanly to proc_open
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($sock);
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $deadPort = (int) substr((string) strrchr($name, ':'), 1);

        $container = new Container($this->baseEnv([
            'IMAGE_DAEMON_URL' => 'http://127.0.0.1:' . $deadPort,
            'IMAGE_DAEMON_TOKEN' => 'secret-token',
        ]));
        $fixture = $container->config->customRoot . '/tests/fixtures/slot-1-v1.jpg';
        $meta = $container->renderer->validateJpeg($fixture);
        self::assertSame(hash_file('sha256', $fixture), $meta['sha256']);
    }

    public function testInvalidNonNumericGuestRateLimitConfigFailsClosedAndDaemonParityHolds(): void
    {
        try {
            new Container($this->baseEnv([
                'GUEST_RATE_LIMIT_MAX_REQUESTS' => 'not_a_number',
            ]));
            self::fail('Expected INVALID_CONFIG for non-numeric GUEST_RATE_LIMIT_MAX_REQUESTS');
        } catch (ApiException $e) {
            self::assertSame('INVALID_CONFIG', $e->getErrorCode());
            self::assertSame(503, $e->getHttpStatus());
        }

        // Start a live Python image_daemon.py instance and verify Renderer live HTTP + trailing slash + frameText parity
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($sock);
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr((string) strrchr($name, ':'), 1);
        $token = 'live-php-daemon-token-' . Uuid::v4();

        $baseContainer = new Container($this->baseEnv(['IMAGE_DAEMON_URL' => '']));
        $customRoot = $baseContainer->config->customRoot;
        $pythonBin = $baseContainer->config->pythonBin;

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->tempDir . '/daemon-out.log', 'a'],
            2 => ['file', $this->tempDir . '/daemon-err.log', 'a'],
        ];
        $env = getenv();
        $env = is_array($env) ? $env : [];
        $env['PYTHONDONTWRITEBYTECODE'] = '1';
        $env['IMAGE_DAEMON_TOKEN'] = $token;
        $proc = proc_open(
            [$pythonBin, '-B', $customRoot . '/python/image_daemon.py', '--host', '127.0.0.1', '--port', (string) $port],
            $desc,
            $pipes,
            $customRoot . '/python',
            $env
        );
        self::assertIsResource($proc);
        fclose($pipes[0]);

        try {
            for ($i = 0; $i < 40; $i++) {
                $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($fp !== false) {
                    fclose($fp);
                    break;
                }
                usleep(100_000);
            }

            $baseCfg = $baseContainer->config;
            $slashCfg = new AppConfig(
                appEnv: $baseCfg->appEnv,
                appVersion: $baseCfg->appVersion,
                appTimezone: $baseCfg->appTimezone,
                customRoot: $baseCfg->customRoot,
                projectRoot: $baseCfg->projectRoot,
                dataDir: $this->tempDir . '/slash-data',
                databasePath: $this->tempDir . '/slash-data/db.sqlite',
                operatorBind: $baseCfg->operatorBind,
                guestBind: $baseCfg->guestBind,
                shareBaseUrl: $baseCfg->shareBaseUrl,
                trustedHosts: $baseCfg->trustedHosts,
                cameraAdapter: 'mock',
                printerAdapter: 'mock',
                printerQueue: 'mock',
                defaultLut: 'original',
                defaultTemplate: 'vertical-three-v1',
                defaultBeautyPreset: 'natural',
                maxJpegBytes: $baseCfg->maxJpegBytes,
                maxImagePixels: $baseCfg->maxImagePixels,
                shareTtlHours: 24,
                sessionRetentionDays: 7,
                auditRetentionDays: 30,
                diskLowBytes: 1024,
                authIdleTimeoutSeconds: 3600,
                shareSigningKey: $baseCfg->shareSigningKey,
                countdownSeconds: 0,
                captureTimeoutSeconds: 5,
                renderTimeoutSeconds: 10,
                printSubmitTimeoutSeconds: 5,
                printQueryTimeoutSeconds: 5,
                workerHeartbeatSeconds: 2,
                workerStaleSeconds: 10,
                sqliteBusyTimeoutMs: 5000,
                mockCaptureDelayMs: 0,
                mockCountdownDelayMs: 0,
                mockFaultScenario: 'none',
                mockPrinterScenario: 'normal',
                mockDiskFreeBytes: null,
                upstreamSha: $baseCfg->upstreamSha,
                pythonBin: $pythonBin,
                imageDaemonUrl: 'http://127.0.0.1:' . $port . '/',
                imageDaemonToken: $token
            );
            $slashCfg->validate();
            self::assertSame('http://127.0.0.1:' . $port, $slashCfg->imageDaemonUrl);

            $daemonRenderer = new Renderer($slashCfg, new StructuredLogger($this->tempDir . '/slash-data'));
            $fixture = $customRoot . '/tests/fixtures/slot-1-v1.jpg';
            $meta = $daemonRenderer->validateJpeg($fixture);
            self::assertSame(hash_file('sha256', $fixture), $meta['sha256']);

            $logFiles = glob($this->tempDir . '/slash-data/logs/*.log') ?: [];
            $logContent = $logFiles !== [] ? (string) file_get_contents($logFiles[0]) : '';
            self::assertStringNotContainsString('DAEMON_HTTP_404', $logContent);

            // Malformed slot definition must yield identical 500 RENDER_FAILED across CLI and Daemon
            $badSlots = [['name' => ['x' => 10, 'y' => 10]]];
            try {
                $baseContainer->renderer->frameText(['name' => 'Test', 'date' => '', 'wish' => ''], $badSlots, false);
                self::fail('Expected CLI frameText to throw RENDER_FAILED');
            } catch (ApiException $cliErr) {
                try {
                    $daemonRenderer->frameText(['name' => 'Test', 'date' => '', 'wish' => ''], $badSlots, false);
                    self::fail('Expected Daemon frameText to throw RENDER_FAILED');
                } catch (ApiException $daemonErr) {
                    self::assertSame($cliErr->getHttpStatus(), $daemonErr->getHttpStatus());
                    self::assertSame($cliErr->getErrorCode(), $daemonErr->getErrorCode());
                    self::assertSame(500, $daemonErr->getHttpStatus());
                    self::assertSame('RENDER_FAILED', $daemonErr->getErrorCode());
                }
            }
        } finally {
            $st = proc_get_status($proc);
            if ($st['running']) {
                @proc_terminate($proc, 9);
            }
            @proc_close($proc);
        }
    }
}
