<?php

declare(strict_types=1);

namespace Photobooth\Operator\Tests;

use PHPUnit\Framework\TestCase;
use Photobooth\Operator\Container;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Uuid;

final class BeautyRetouchTest extends TestCase
{
    private string $tempDir;
    private Container $container;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pb-beauty-test-' . Uuid::v4();
        mkdir($this->tempDir, 0750, true);

        $env = [
            'DATA_DIR' => $this->tempDir,
            'DATABASE_PATH' => $this->tempDir . '/test.sqlite',
            'CAMERA_ADAPTER' => 'mock',
            'DEFAULT_BEAUTY_PRESET' => 'natural',
            'SHARE_SIGNING_KEY' => 'test_signing_key_for_beauty_retouch_0123456789',
        ];

        $this->container = new Container($env);
    }

    protected function tearDown(): void
    {
        $this->container->assetStore->removeDirectoryRecursive($this->tempDir);
    }

    private function getOperatorUserId(): string
    {
        $stmt = $this->container->db->pdo()->query("SELECT id FROM users WHERE role = 'operator' LIMIT 1");
        $row = $stmt !== false ? $stmt->fetch() : false;
        if (is_array($row) && isset($row['id'])) {
            return (string) $row['id'];
        }
        $userId = Uuid::v4();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->container->db->pdo()->prepare(
            "INSERT INTO users VALUES (?, 'operator_test', 'hash', 'operator', NULL, ?, ?)"
        )->execute([$userId, $now, $now]);
        return $userId;
    }

    public function testSessionCreationHasDefaultBeautyPreset(): void
    {
        $userId = $this->getOperatorUserId();
        $resp = $this->container->sessionService->createSession($userId, 'idem-beauty-create-1');
        $session = $resp['body'];

        self::assertArrayHasKey('beauty', $session);
        self::assertSame('natural', $session['beauty']['preset']);
        self::assertSame(20, $session['beauty']['smooth']);
        self::assertSame(5, $session['beauty']['brighten']);
        self::assertSame(10, $session['beauty']['tone']);
        self::assertTrue($session['beauty']['enabled']);
    }

    public function testConfigureBeautyPresetsAndSliders(): void
    {
        $userId = $this->getOperatorUserId();
        $resp = $this->container->sessionService->createSession($userId, 'idem-beauty-create-2');
        $session = $resp['body'];
        $sessionId = (string) $session['id'];
        $rev = (int) $session['revision'];

        // Switch to Korean Glow preset
        $cfgResp = $this->container->sessionService->configure(
            $sessionId,
            $userId,
            $rev,
            ['beauty_preset' => 'korean-glow'],
            'idem-beauty-cfg-kg'
        );
        $updated = $cfgResp['body'];
        self::assertSame('korean-glow', $updated['beauty']['preset']);
        self::assertSame(55, $updated['beauty']['smooth']);
        self::assertSame(15, $updated['beauty']['brighten']);
        self::assertSame(20, $updated['beauty']['tone']);
        self::assertTrue($updated['beauty']['enabled']);

        // Switch to Off
        $rev = (int) $updated['revision'];
        $cfgResp2 = $this->container->sessionService->configure(
            $sessionId,
            $userId,
            $rev,
            ['beauty_preset' => 'off'],
            'idem-beauty-cfg-off'
        );
        $updated2 = $cfgResp2['body'];
        self::assertSame('off', $updated2['beauty']['preset']);
        self::assertSame(0, $updated2['beauty']['smooth']);
        self::assertSame(0, $updated2['beauty']['brighten']);
        self::assertSame(0, $updated2['beauty']['tone']);
        self::assertFalse($updated2['beauty']['enabled']);

        // Custom sliders
        $rev = (int) $updated2['revision'];
        $cfgResp3 = $this->container->sessionService->configure(
            $sessionId,
            $userId,
            $rev,
            [
                'beauty_preset' => 'natural',
                'beauty_smooth' => 45,
                'beauty_brighten' => 12,
                'beauty_tone' => 18,
            ],
            'idem-beauty-cfg-custom'
        );
        $updated3 = $cfgResp3['body'];
        self::assertSame('natural', $updated3['beauty']['preset']);
        self::assertSame(45, $updated3['beauty']['smooth']);
        self::assertSame(12, $updated3['beauty']['brighten']);
        self::assertSame(18, $updated3['beauty']['tone']);
    }

    public function testInvalidBeautyPresetThrowsException(): void
    {
        $userId = $this->getOperatorUserId();
        $resp = $this->container->sessionService->createSession($userId, 'idem-beauty-create-3');
        $session = $resp['body'];

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Preset làm đẹp không hợp lệ');

        $this->container->sessionService->configure(
            (string) $session['id'],
            $userId,
            (int) $session['revision'],
            ['beauty_preset' => 'nonexistent_mode'],
            'idem-beauty-cfg-invalid'
        );
    }
}
