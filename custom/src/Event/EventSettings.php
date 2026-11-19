<?php

declare(strict_types=1);

namespace Photobooth\Operator\Event;

use PDO;
use Photobooth\Operator\Catalog\TemplateCatalog;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Session\IdempotencyService;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Uuid;

final class EventSettings
{
    public function __construct(
        private Database $db,
        private TemplateCatalog $catalog,
        private Renderer $renderer,
        private string $printMode,
        private int $defaultTtl
    ) {}

    public static function current(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM event_settings WHERE is_current=1')->fetch();
    }

    public static function snapshot(string $json): array
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR) + [
            'name' => '', 'footer' => '', 'color' => '#3b82f6', 'category' => 'all',
            'frame' => ['name' => '', 'date' => '', 'wish' => ''],
            'logo' => null, 'banner' => null, 'qr_ttl_hours' => null,
        ];
    }

    public static function hasFrameText(array $frame): bool
    {
        return $frame['name'] !== '' || $frame['date'] !== '' || $frame['wish'] !== '';
    }

    public static function activeSession(PDO $pdo): bool
    {
        return (bool) $pdo->query("SELECT EXISTS(SELECT 1 FROM sessions
            WHERE deleted_at IS NULL AND state NOT IN ('CLOSED', 'CANCELLED'))")->fetchColumn();
    }

    public static function quota(PDO $pdo, string $id, string $mode): array
    {
        $query = $pdo->prepare("SELECT e.print_limit,
            COALESCE(SUM(CASE WHEN p.status='COMPLETED' THEN COALESCE(p.completed_copies, p.copies) ELSE 0 END),0)
            + COALESCE((SELECT copies FROM event_deleted_print_totals WHERE event_id=e.id AND adapter_mode=:mode),0) AS printed,
            COALESCE(SUM(CASE WHEN p.status NOT IN ('FAILED','COMPLETED') THEN p.copies ELSE 0 END),0) AS reserved
            FROM event_settings e LEFT JOIN sessions s ON s.event_id=e.id
            LEFT JOIN print_requests p ON p.session_id=s.id AND p.adapter_mode=:mode
            WHERE e.id=:id GROUP BY e.id");
        $query->execute([':id' => $id, ':mode' => $mode]);
        $row = $query->fetch();
        $limit = $row['print_limit'] === null ? null : (int) $row['print_limit'];
        $printed = (int) $row['printed'];
        $reserved = (int) $row['reserved'];
        return [
            'mode' => $mode, 'limit' => $limit, 'printed' => $printed, 'reserved' => $reserved,
            'remaining' => $limit === null ? null : max(0, $limit - $printed - $reserved),
        ];
    }

    public static function brandImage(PDO $pdo, ?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $query = $pdo->prepare('SELECT data_uri FROM event_brand_images WHERE id=?');
        $query->execute([$id]);
        return $query->fetchColumn() ?: null;
    }

    public function view(bool $includeImages = true): array
    {
        $pdo = $this->db->pdo();
        $event = self::current($pdo);
        $config = self::snapshot($event['configuration']);
        $config['qr_ttl_hours'] ??= $this->defaultTtl;
        return [
            'id' => $event['id'], 'revision' => (int) $event['revision'],
            'configuration' => $config, 'locked' => self::activeSession($pdo),
            ...($includeImages ? ['logo_url' => self::brandImage($pdo, $config['logo']),
                'banner_url' => self::brandImage($pdo, $config['banner'])] : []),
            'quota' => self::quota($pdo, $event['id'], $this->printMode),
        ];
    }

    public function save(array $payload, string $actor, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $hash = IdempotencyService::hashPayload($payload);
        $pdo = $this->db->pdo();
        $replay = IdempotencyService::checkReplay($pdo, $actor, 'event.save', $key, $hash);
        if ($replay !== null) {
            return $this->view();
        }
        $this->assertUnlocked($pdo);
        $category = $payload['category'] ?? 'all';
        if (!in_array($category, ['all', 'wedding', 'birthday', 'other'], true)) {
            throw new ApiException('INVALID_INPUT', 'Chủ đề template không hợp lệ.', 422);
        }
        $frame = self::frame($payload['frame'] ?? []);
        $templates = $this->catalog->eventTemplates($category, self::hasFrameText($frame));
        if ($templates === []) {
            throw new ApiException('NO_EVENT_TEMPLATES', 'Chủ đề chưa có template đang hoạt động.', 422);
        }
        $slots = array_column($templates, 'text_slots');
        if (self::hasFrameText($frame) && $slots === []) {
            throw new ApiException('INVALID_FRAME_TEXT', 'Chủ đề chưa có khung hỗ trợ chữ.', 422);
        }
        if (self::hasFrameText($frame)) {
            $this->renderer->frameText($frame, $slots);
        }
        $limit = $payload['print_limit'] ?? null;
        $ttl = $payload['qr_ttl_hours'] ?? $this->defaultTtl;
        $color = $payload['color'] ?? '#3b82f6';
        if (($limit !== null && (!is_int($limit) || $limit < 1 || $limit > 1_000_000))
            || !is_int($ttl) || $ttl < 1 || $ttl > 8760
            || !is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $color)) {
            throw new ApiException('INVALID_INPUT', 'Kiểm tra hạn mức in, thời hạn QR và màu sự kiện.', 422);
        }
        $config = [
            'category' => $category, 'frame' => $frame, 'color' => $color, 'qr_ttl_hours' => $ttl,
            'name' => self::line($payload['name'] ?? '', 100, 'Tên sự kiện'),
            'footer' => self::line($payload['footer'] ?? '', 200, 'Lời nhắn'),
        ];

        return $this->db->transaction(function (PDO $pdo) use ($payload, $config, $limit, $actor, $key, $hash): array {
            if (IdempotencyService::checkReplay($pdo, $actor, 'event.save', $key, $hash) !== null) {
                return $this->view();
            }
            $this->assertUnlocked($pdo);
            $event = self::current($pdo);
            if (($payload['id'] ?? null) !== $event['id'] || ($payload['revision'] ?? null) !== (int) $event['revision']) {
                throw new ApiException('REVISION_CONFLICT', 'Cài đặt đã thay đổi. Hãy mở lại màn hình Cài đặt.', 409);
            }
            $previous = self::snapshot($event['configuration']);
            foreach (['logo', 'banner'] as $field) {
                $config[$field] = array_key_exists($field, $payload)
                    ? $this->storeImage($pdo, $payload[$field]) : $previous[$field];
            }
            $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $id = $event['id'];
            if (($payload['new_event'] ?? false) === true) {
                $id = Uuid::v4();
                $pdo->exec('UPDATE event_settings SET is_current=0 WHERE is_current=1');
                $pdo->prepare('INSERT INTO event_settings(id, configuration, print_limit, is_current) VALUES (?, ?, ?, 1)')
                    ->execute([$id, $json, $limit]);
            } else {
                $pdo->prepare('UPDATE event_settings SET configuration=?, print_limit=?, revision=revision+1 WHERE id=?')
                    ->execute([$json, $limit, $id]);
            }
            IdempotencyService::record($pdo, $actor, 'event.save', $key, null, $hash, $id, 200, ['id' => $id]);
            return $this->view();
        });
    }

    public static function frame(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ApiException('INVALID_FRAME_TEXT', 'Nội dung frame không hợp lệ.', 422);
        }
        $date = $value['date'] ?? '';
        if ($date !== '' && (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
            throw new ApiException('INVALID_FRAME_TEXT', 'Ngày trên frame không hợp lệ.', 422);
        }
        return [
            'name' => self::line($value['name'] ?? '', 80, 'Tên trên frame'),
            'date' => $date,
            'wish' => self::line($value['wish'] ?? '', 100, 'Lời chúc'),
        ];
    }

    private static function line(mixed $value, int $length, string $label): string
    {
        if (!is_string($value) || !preg_match('/^[^\p{C}]*$/uD', $value) || mb_strlen($value) > $length) {
            throw new ApiException('INVALID_INPUT', $label . ' vượt giới hạn hoặc chứa ký tự không hợp lệ.', 422);
        }
        return trim($value);
    }

    private function assertUnlocked(PDO $pdo): void
    {
        if (self::activeSession($pdo)) {
            throw new ApiException('EVENT_SETTINGS_LOCKED', 'Hãy đóng hoặc hủy phiên hiện tại để sửa cài đặt.', 409);
        }
    }

    private function storeImage(PDO $pdo, mixed $uri): ?string
    {
        if ($uri === null) {
            return null;
        }
        $error = new ApiException('INVALID_IMAGE', 'Logo và banner phải là PNG hoặc JPEG, tối đa 512 KiB và 2000 × 2000 px.', 422);
        if (!is_string($uri) || strlen($uri) > 699100
            || !preg_match('#^data:image/(png|jpeg);base64,([a-zA-Z0-9+/=]+)$#D', $uri, $parts)) {
            throw $error;
        }
        $data = base64_decode($parts[2], true);
        $size = $data === false ? false : @getimagesizefromstring($data);
        if (!$size || strlen($data) > 512 * 1024 || $size['mime'] !== 'image/' . $parts[1]
            || $size[0] > 2000 || $size[1] > 2000 || !@imagecreatefromstring($data)) {
            throw $error;
        }
        $id = hash('sha256', $data);
        $pdo->prepare('INSERT OR IGNORE INTO event_brand_images(id, data_uri) VALUES (?, ?)')
            ->execute([$id, 'data:' . $size['mime'] . ';base64,' . base64_encode($data)]);
        return $id;
    }
}
