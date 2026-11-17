<?php

declare(strict_types=1);

namespace Photobooth\Operator\Tests;

use PHPUnit\Framework\TestCase;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Maintenance\StructuredLogger;

final class LkMagicConfigTest extends TestCase
{
    public function testResolvedDotEnvSettingsReachPythonWithoutLosingInheritedEnvironment(): void
    {
        $directory = sys_get_temp_dir() . '/pb-lk-config-' . bin2hex(random_bytes(8));
        mkdir($directory . '/python', 0750, true);
        $keys = ['BEAUTY_ENGINE', 'LKMAGIC_BRIDGE_URL', 'LKMAGIC_EXECUTABLE', 'LKMAGIC_AUTO_START', 'PB_TEST_INHERITED'];
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = getenv($key);
            putenv($key);
        }
        putenv('PB_TEST_INHERITED=kept');
        try {
            file_put_contents($directory . '/.env', implode("\n", [
                'BEAUTY_ENGINE=mediapipe',
                'LKMAGIC_BRIDGE_URL=http://127.0.0.1:5544',
                'LKMAGIC_EXECUTABLE="C:/Applications/LK Magic.exe"',
                'LKMAGIC_AUTO_START=false',
            ]));
            file_put_contents($directory . '/python/render_pipeline.py', <<<'PY'
import json, os
print(json.dumps({"status": "ok", "env": {key: os.getenv(key) for key in (
    "BEAUTY_ENGINE", "LKMAGIC_BRIDGE_URL", "LKMAGIC_EXECUTABLE", "LKMAGIC_AUTO_START", "PB_TEST_INHERITED"
)}}))
PY);
            $python = getenv('PYTHON_BIN') ?: (PHP_OS_FAMILY === 'Windows'
                ? dirname(__DIR__, 2) . '/desktop/runtime/python/python.exe' : 'python3');
            $config = AppConfig::fromEnvironment(['PYTHON_BIN' => $python], $directory);
            $renderer = new Renderer($config, new StructuredLogger($directory));
            $result = $renderer->renderFromManifest($directory . '/pending', []);
            self::assertSame([
                'BEAUTY_ENGINE' => 'mediapipe',
                'LKMAGIC_BRIDGE_URL' => 'http://127.0.0.1:5544',
                'LKMAGIC_EXECUTABLE' => 'C:/Applications/LK Magic.exe',
                'LKMAGIC_AUTO_START' => '0',
                'PB_TEST_INHERITED' => 'kept',
            ], $result['env']);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
}
