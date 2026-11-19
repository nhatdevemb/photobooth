<?php

declare(strict_types=1);

namespace Photobooth\Operator\Image;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;

final class BeautyService
{
    public function __construct(
        private Database $db,
        private AppConfig $config,
        private AssetStore $assets,
        private Renderer $renderer
    ) {}

    public function cacheDir(string $sessionId): string
    {
        return $this->config->dataDir . '/beauty/sessions/' . $sessionId;
    }

    public static function progressKey(array $snapshot): string
    {
        return hash('sha256', json_encode([
            $snapshot['beauty_preset'],
            $snapshot['beauty_config'],
            array_column($snapshot['photos'], 'sha256'),
            $snapshot['retry_lkmagic'] ?? false,
        ], JSON_THROW_ON_ERROR));
    }

    public function health(): array
    {
        $dir = $this->config->dataDir . '/beauty';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $path = $dir . '/health.json';
        $cache = @fopen($path, 'c+');
        $bridge = ['status' => 'unavailable', 'reason' => 'HEALTH_CHECK_UNAVAILABLE', 'checked_at' => Clock::nowIso()];
        if ($cache !== false) {
            try {
                if (flock($cache, LOCK_EX)) {
                    $cached = json_decode((string) stream_get_contents($cache), true);
                    if (is_array($cached) && ($cached['url'] ?? null) === $this->config->lkmagicBridgeUrl
                        && time() - ($cached['unix'] ?? 0) < 5) {
                        $bridge = $cached['bridge'];
                    } else {
                        $bridge = $this->probeBridge();
                        rewind($cache);
                        ftruncate($cache, 0);
                        fwrite($cache, json_encode(['unix' => time(), 'url' => $this->config->lkmagicBridgeUrl, 'bridge' => $bridge], JSON_INVALID_UTF8_SUBSTITUTE));
                    }
                }
            } finally {
                fclose($cache);
            }
        }

        $pdo = $this->db->pdo();
        $check = $pdo->query('SELECT status, requested_at, completed_at, duration_ms, error FROM beauty_diagnostic WHERE id = 1')->fetch();
        $job = $pdo->query("SELECT result, completed_at FROM jobs WHERE type = 'render' AND status = 'SUCCEEDED' ORDER BY completed_at DESC LIMIT 1")->fetch();
        $result = $job ? json_decode((string) $job['result'], true) : null;
        return [
            'bridge' => $bridge,
            'diagnostic' => $check ?: null,
            'last_render' => $job ? [
                'completed_at' => $job['completed_at'],
                'mode' => self::renderMode($result ?? []),
            ] : null,
        ];
    }

    private function probeBridge(): array
    {
        $status = 'unavailable';
        $reason = 'BRIDGE_UNREACHABLE';
        if (function_exists('curl_init')) {
            $curl = curl_init($this->config->lkmagicBridgeUrl . '/api/v1/health');
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 800,
                CURLOPT_CONNECTTIMEOUT_MS => 400,
                CURLOPT_PROXY => '',
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($curl);
            $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($http === 200 && is_string($body)) {
                $data = json_decode($body, true);
                if (($data['ok'] ?? null) === true && is_bool($data['ready'] ?? null)) {
                    $status = $data['ready'] ? 'ready' : 'starting';
                    $reason = $data['ready'] ? null : 'NATIVE_NOT_READY';
                } else {
                    $reason = 'INVALID_BRIDGE_HEALTH';
                }
            } elseif ($http !== 0) {
                $reason = 'HTTP_' . $http;
            }
        }
        return ['status' => $status, 'reason' => $reason, 'checked_at' => Clock::nowIso()];
    }

    public static function renderMode(array $result): string
    {
        $audit = $result['beauty_audit'] ?? [];
        if ($audit === []) {
            return 'unknown';
        }
        if (in_array('error_fallback', array_column($audit, 'status'), true)) {
            return 'error';
        }
        if (($result['beauty_fallback'] ?? null) !== null) {
            return 'fallback';
        }
        if (array_unique(array_column($audit, 'status')) === ['bypassed']) {
            return 'off';
        }
        $engines = array_unique(array_map(static fn (array $item): string => $item['engine'] ?? 'unknown', $audit));
        return count($engines) === 1 ? reset($engines) : 'unknown';
    }

    public function requestDiagnostic(): array
    {
        $this->db->pdo()->prepare(
            "INSERT INTO beauty_diagnostic (id, status, requested_at) VALUES (1, 'queued', :now)
             ON CONFLICT (id) DO UPDATE SET status='queued', requested_at=excluded.requested_at,
                 completed_at=NULL, duration_ms=NULL, error=NULL
             WHERE beauty_diagnostic.status NOT IN ('queued', 'running')"
        )->execute([':now' => Clock::nowIso()]);
        return ['status' => $this->db->pdo()->query('SELECT status FROM beauty_diagnostic WHERE id=1')->fetchColumn()];
    }

    public function recoverDiagnostic(): void
    {
        $this->db->pdo()->exec("UPDATE beauty_diagnostic SET status='queued' WHERE status='running'");
    }

    public function runDiagnostic(): bool
    {
        $pdo = $this->db->pdo();
        if (!$pdo->query("SELECT 1 FROM beauty_diagnostic WHERE status='queued'")->fetchColumn()) {
            return false;
        }
        return $this->renderer->withBeautyLock(function () use ($pdo): bool {
            if (!$pdo->exec("UPDATE beauty_diagnostic SET status='running' WHERE status='queued'")) {
                return false;
            }
            $started = microtime(true);
            try {
                $result = $this->renderer->runBeautyTask([
                    'task' => 'check_lkmagic',
                    'beauty_preset' => 'natural',
                    'photos' => [['slot' => 1, 'path' => $this->config->customRoot . '/assets/beauty-check.jpg']],
                ]);
                $success = ($result['status'] ?? null) === 'ok' && ($result['audit']['engine'] ?? null) === 'lkmagic_native';
                $error = $success ? null : ($result['message'] ?? 'LK Magic không xuất được ảnh thử.');
            } catch (\Throwable $e) {
                $success = false;
                $error = $e->getMessage();
            }
            $pdo->prepare('UPDATE beauty_diagnostic SET status=:status, completed_at=:now, duration_ms=:ms, error=:error WHERE id=1')
                ->execute([':status' => $success ? 'passed' : 'failed', ':now' => Clock::nowIso(),
                    ':ms' => (int) round((microtime(true) - $started) * 1000), ':error' => $error]);
            return true;
        }, false) ?? false;
    }

    public function pendingPreparation(): ?array
    {
        $pdo = $this->db->pdo();
        $session = $pdo->query(
            "SELECT id, capture_count, beauty_preset, beauty_smooth, beauty_brighten, beauty_tone FROM sessions
             WHERE deleted_at IS NULL AND ui_step=4 AND state IN ('CAPTURED','REVIEW') ORDER BY updated_at DESC LIMIT 1"
        )->fetch();
        if (!$session || $session['beauty_preset'] === 'off'
            || !((int) $session['beauty_smooth'] || (int) $session['beauty_brighten'] || (int) $session['beauty_tone'])) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT ss.slot, p.path, p.sha256 FROM session_slots ss JOIN photos p ON p.id=ss.current_photo_id
             WHERE ss.session_id=:id ORDER BY ss.slot'
        );
        $stmt->execute([':id' => $session['id']]);
        $photos = $stmt->fetchAll();
        if (count($photos) !== (int) $session['capture_count']) {
            return null;
        }
        foreach ($photos as &$photo) {
            $photo['path'] = $this->assets->absolutePath($photo['path']);
        }
        unset($photo);

        $snapshot = [
            'task' => 'prepare_beauty',
            'beauty_session_id' => $session['id'],
            'beauty_cache_dir' => $this->cacheDir($session['id']),
            'database_path' => $this->config->databasePath,
            'beauty_preset' => $session['beauty_preset'],
            'beauty_config' => [
                'smooth' => (int) $session['beauty_smooth'],
                'brighten' => (int) $session['beauty_brighten'],
                'tone' => (int) $session['beauty_tone'],
            ],
            'photos' => $photos,
        ];
        $snapshot['beauty_progress_key'] = self::progressKey($snapshot);
        return $snapshot;
    }

    public function cleanClosedCaches(): void
    {
        foreach (glob($this->config->dataDir . '/beauty/sessions/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $stmt = $this->db->pdo()->prepare("SELECT 1 FROM sessions WHERE id=:id AND deleted_at IS NULL AND state NOT IN ('CLOSED','CANCELLED')");
            $stmt->execute([':id' => basename($dir)]);
            if (!$stmt->fetchColumn()) {
                $this->renderer->withBeautyLock(fn () => $this->assets->removeDirectoryRecursive($dir), false);
            }
        }
    }
}
