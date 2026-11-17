<?php

declare(strict_types=1);

namespace Photobooth\Operator\Tests;

use Photobooth\Operator\Camera\DigiCamControlCameraAdapter;
use Photobooth\Operator\Camera\DigiCamControlClient;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Support\ApiException;
use PHPUnit\Framework\TestCase;

final class DigiCamControlTest extends TestCase
{
    private string $directory;
    private AppConfig $config;

    protected function setUp(): void
    {
        $this->directory = str_replace('\\', '/', sys_get_temp_dir()) . '/pb-dcc-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/pending', 0750, true);
        $this->config = AppConfig::fromEnvironment(['DATA_DIR' => $this->directory, 'CAMERA_ADAPTER' => 'digicamcontrol']);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($this->directory);
    }

    private function adapter(callable $transport): DigiCamControlCameraAdapter
    {
        return new DigiCamControlCameraAdapter($this->config, new Renderer($this->config, new StructuredLogger($this->directory)), new DigiCamControlClient($this->config, $transport));
    }

    public function testRejectsRemoteDccAndCredentials(): void
    {
        foreach (['http://192.168.1.2:5513', 'http://user@127.0.0.1:5513', 'http://127.0.0.1:5513/?CMD=Capture', 'https://127.0.0.1:5513'] as $url) {
            try { AppConfig::fromEnvironment(['DCC_BASE_URL' => $url]); self::fail('URL accepted: ' . $url); }
            catch (ApiException $error) { self::assertSame('INVALID_DCC_CONFIG', $error->getErrorCode()); }
        }
    }

    public function testRequiresUniqueConnectedCamera(): void
    {
        foreach (['', '12345\n67890', '<html>no camera</html>', 'OK'] as $response) {
            $adapter = $this->adapter(static fn () => str_replace('\\n', "\n", $response));
            self::assertSame('error', $adapter->health()['camera_state']);
        }
        self::assertSame('connected', $this->adapter(static fn () => '12345')->health()['camera_state']);
    }

    public function testTimeoutAfterTriggerIsNeverRetried(): void
    {
        $triggers = 0;
        $settings = [];
        $adapter = $this->adapter(function (string $url) use (&$triggers, &$settings): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if (($q['slc'] ?? '') === 'list') { return '12345'; }
            if (($q['slc'] ?? '') === 'capture') { $triggers++; throw new ApiException('DCC_UNAVAILABLE', 'timeout after shutter', 503); }
            if (($q['slc'] ?? '') === 'set') {
                if (!in_array($q['param1'] ?? '', ['camera', 'session', 'session.folder', 'session.filenametemplate'], true)) { return 'Unknow parameter'; }
                $settings[$q['param1']] = $q['param2'];
                return 'OK';
            }
            return $q['param1'] === 'session' ? 'Photobooth' : ($settings[$q['param1']] ?? 'Unknow parameter');
        });
        for ($i = 0; $i < 2; $i++) {
            try { $adapter->capture($this->directory . '/pending', 1, 1, time() + 5); self::fail('Should fail'); }
            catch (ApiException $e) { self::assertSame($i === 0 ? 'DCC_UNAVAILABLE' : 'DCC_CAPTURE_UNCERTAIN', $e->getErrorCode()); }
        }
        self::assertSame(1, $triggers);
    }

    public function testRejectsStaleOrAmbiguousImages(): void
    {
        $settings = [];
        $adapter = $this->adapter(function (string $url) use (&$settings): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if ($q['slc'] === 'list') { return '12345'; }
            if ($q['slc'] === 'set') {
                if (!in_array($q['param1'] ?? '', ['camera', 'session', 'session.folder', 'session.filenametemplate'], true)) { return 'Unknow parameter'; }
                $settings[$q['param1']] = $q['param2'];
                return 'OK';
            }
            if ($q['slc'] === 'capture') {
                file_put_contents($settings['session.folder'] . '/old.jpg', 'stale');
                return 'OK';
            }
            return $q['param1'] === 'session' ? 'Photobooth' : ($settings[$q['param1']] ?? 'Unknow parameter');
        });
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('ảnh ngoài luồng');
        $adapter->capture($this->directory . '/pending', 1, 1, time() + 5);
    }

    public function testFullJpegIsPreservedAndReplayDoesNotTrigger(): void
    {
        $settings = [];
        $last = '-';
        $triggers = 0;
        $fixture = dirname(__DIR__) . '/fixtures/slot-1.jpg';
        // A real JPEG with no dependence on the image pipeline to generate the fixture.
        $image = imagecreatetruecolor(96, 64);
        ob_start(); imagejpeg($image, null, 95); $jpeg = ob_get_clean(); imagedestroy($image);
        $adapter = $this->adapter(function (string $url) use (&$settings, &$last, &$triggers, $jpeg): string {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            if ($q['slc'] === 'list') { return '12345'; }
            if ($q['slc'] === 'set') {
                if (!in_array($q['param1'] ?? '', ['camera', 'session', 'session.folder', 'session.filenametemplate'], true)) { return 'Unknow parameter'; }
                $settings[$q['param1']] = $q['param2'];
                return 'OK';
            }
            if ($q['slc'] === 'capture') {
                $triggers++;
                $last = $settings['session.filenametemplate'] . '.jpg';
                file_put_contents($settings['session.folder'] . '/' . $last, $jpeg);
                return 'OK';
            }
            if ($q['param1'] === 'lastcaptured') { return $last; }
            return $q['param1'] === 'session' ? 'Photobooth' : ($settings[$q['param1']] ?? 'Unknow parameter');
        });
        $first = $adapter->capture($this->directory . '/pending', 1, 1, time() + 10);
        $second = $adapter->capture($this->directory . '/pending', 1, 1, time() + 10);
        self::assertSame(hash('sha256', $jpeg), $first['sha256']);
        self::assertSame($first, $second);
        self::assertSame(1, $triggers);
    }

    public function testRejectsNonJpegLiveView(): void
    {
        $adapter = $this->adapter(function (string $url): string {
            return parse_url($url, PHP_URL_PATH) === '/liveview.jpg' ? '<html>Error</html>' : '12345';
        });
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('live view JPEG');
        $adapter->previewFrame();
    }

    public function testIncomingRetentionCannotRaceCapture(): void
    {
        $container = new \Photobooth\Operator\Container(['DATA_DIR' => $this->directory, 'DATABASE_PATH' => $this->directory . '/retention.sqlite']);
        $old = $this->directory . '/dcc/incoming/' . str_repeat('a', 64);
        $fresh = $this->directory . '/dcc/incoming/' . str_repeat('b', 64);
        mkdir($old, 0750, true); mkdir($fresh, 0750, true);
        file_put_contents($old . '/old.jpg', 'old'); file_put_contents($fresh . '/fresh.jpg', 'fresh');
        touch($old, time() - 8 * 86400);
        $lock = fopen($this->directory . '/dcc/camera.lock', 'c+');
        flock($lock, LOCK_EX);
        self::assertSame(0, $container->maintenanceService->runMaintenancePass()['dcc_incoming_purged']);
        self::assertFileExists($old . '/old.jpg');
        fclose($lock);
        self::assertSame(1, $container->maintenanceService->runMaintenancePass()['dcc_incoming_purged']);
        self::assertDirectoryDoesNotExist($old);
        self::assertFileExists($fresh . '/fresh.jpg');
        unset($container);
    }
}
