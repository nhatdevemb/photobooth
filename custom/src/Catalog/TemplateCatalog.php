<?php

declare(strict_types=1);

namespace Photobooth\Operator\Catalog;

use PDO;
use Throwable;
use ZipArchive;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Session\IdempotencyService;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;

final class TemplateCatalog
{
    private AppConfig $config;
    private Database $db;
    /** @var ?array<string, array<string, mixed>> */
    private ?array $bundledCache = null;
    /** @var array<string, array<string, mixed>> */
    private array $layoutCache = [];

    public function __construct(AppConfig $config, Database $db)
    {
        $this->config = $config;
        $this->db = $db;
    }

    public function layout(string $id, string $version = '1.0.0'): array
    {
        $this->validateIdentity($id, $version);
        $cacheKey = $id . '@' . $version;
        if (isset($this->layoutCache[$cacheKey])) {
            return $this->layoutCache[$cacheKey];
        }
        $path = $this->config->customRoot . '/layouts/' . $id . '.json';
        $layout = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($layout) || $layout['version'] !== $version) {
            throw new ApiException('INVALID_LAYOUT', 'Bố cục hoặc phiên bản không tồn tại.', 422);
        }
        $this->layoutCache[$cacheKey] = $layout;
        return $layout;
    }

    public function layouts(): array
    {
        $items = [];
        foreach (['single', 'strip-three', 'postcard-three', 'grid-four'] as $id) {
            $layout = $this->layout($id);
            $layout['thumbnail'] = '/custom/api/catalog/layouts/' . $id . '/1.0.0/preview.jpg';
            $items[] = $layout;
        }
        return $items;
    }

    private function bundled(): array
    {
        if ($this->bundledCache !== null) {
            return $this->bundledCache;
        }
        $items = [];
        foreach (glob($this->config->customRoot . '/templates/bundled/*/template.json') ?: [] as $file) {
            $item = json_decode((string) file_get_contents($file), true);
            if (is_array($item)) {
                $item['_directory'] = dirname($file);
                $item['status'] = 'active';
                $items[$item['id'] . '/' . $item['version']] = $item;
            }
        }
        $this->bundledCache = $items;
        return $items;
    }

    /**
     * Load merged bundled + DB catalog items keyed by "id/version" with _directory preserved.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rawCatalogItems(): array
    {
        $items = $this->bundled();
        foreach ($this->db->pdo()->query('SELECT * FROM template_catalog')->fetchAll() as $row) {
            $key = $row['id'] . '/' . $row['version'];
            $item = json_decode((string) $row['manifest'], true);
            if (!is_array($item)) {
                continue;
            }
            $item['status'] = $row['status'];
            $item['_directory'] = $items[$key]['_directory'] ?? ($this->config->dataDir . '/templates/' . $key);
            $items[$key] = $item;
        }
        return $items;
    }

    public function templates(?string $layoutId = null, bool $admin = false): array
    {
        $items = $this->rawCatalogItems();
        $result = [];
        foreach ($items as $item) {
            if ((!$admin && $item['status'] !== 'active') || ($layoutId !== null && $item['layout_id'] !== $layoutId)) {
                continue;
            }
            $result[] = $this->publicItem($item);
        }
        return $result;
    }

    public function eventTemplates(string $category, bool $personalized, ?string $layoutId = null): array
    {
        $items = [];
        foreach ($this->templates($layoutId) as $item) {
            if ((!$personalized && isset($item['text_slots'])) || ($category !== 'all' && $item['category'] !== $category)) {
                continue;
            }
            $previous = $items[$item['id']] ?? null;
            $preferred = isset($item['text_slots']) === $personalized;
            if ($previous === null || ($preferred && isset($previous['text_slots']) !== $personalized)
                || ($preferred === (isset($previous['text_slots']) === $personalized)
                    && version_compare($item['version'], $previous['version'], '>'))) {
                $items[$item['id']] = $item;
            }
        }
        return array_values($items);
    }

    private function publicItem(array $item): array
    {
        unset($item['_directory']);
        $item['category'] = $item['category'] ?? match (true) {
            str_starts_with($item['id'], 'wedding-') => 'wedding',
            str_starts_with($item['id'], 'birthday-') => 'birthday',
            default => 'other',
        };
        $base = '/custom/api/catalog/templates/' . $item['id'] . '/' . $item['version'] . '/';
        $item['preview_url'] = $base . 'preview.jpg';
        $item['overlay_url'] = $base . 'overlay.png';
        $item['background_url'] = isset($item['background']) ? $base . 'background.png' : null;
        return $item;
    }

    /**
     * Lookup template metadata and directory without hashing artwork files on disk.
     *
     * @param ?array<string, array<string, mixed>> $rawItems
     * @return array<string, mixed>
     */
    private function lookupRecord(string $id, ?string $version = null, bool $requireActive = false, ?array $rawItems = null): array
    {
        $this->validateIdentity($id, $version ?? '1.0.0');
        $all = $rawItems ?? $this->rawCatalogItems();
        $choices = [];
        foreach ($all as $raw) {
            if ($raw['id'] === $id && ($version === null || $raw['version'] === $version)
                && (!$requireActive || $raw['status'] === 'active')) {
                $directory = $raw['_directory'];
                $pub = $this->publicItem($raw);
                $pub['_directory'] = $directory;
                $choices[] = $pub;
            }
        }
        usort($choices, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        if ($choices === []) {
            throw new ApiException('INVALID_TEMPLATE', 'Template không tồn tại hoặc chưa được kích hoạt.', 422);
        }
        $item = $choices[0];
        $this->layout($item['layout_id'], $item['layout_version']);
        return $item;
    }

    public function resolve(string $id, ?string $version = null, bool $requireActive = false): array
    {
        $item = $this->lookupRecord($id, $version, $requireActive);
        foreach ($item['hashes'] as $name => $expected) {
            $path = $item['_directory'] . '/' . $name;
            if (!is_file($path) || !hash_equals($expected, hash_file('sha256', $path) ?: '')) {
                throw new ApiException('INTEGRITY_ERROR', 'Tài nguyên template đã bị thay đổi.', 409);
            }
        }
        return $item;
    }

    public function describe(string $id, string $version): array
    {
        return $this->publicItem($this->lookupRecord($id, $version));
    }

    /**
     * Build a public metadata map keyed by "id@version" with a single DB read and zero artwork disk hashes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function describeMap(): array
    {
        $map = [];
        foreach ($this->rawCatalogItems() as $raw) {
            $map[$raw['id'] . '@' . $raw['version']] = $this->publicItem($raw);
        }
        return $map;
    }

    public function asset(string $id, string $version, string $name, bool $admin): string
    {
        $item = $this->resolve($id, $version);
        if ($item['status'] === 'draft' && !$admin) {
            throw new ApiException('FORBIDDEN', 'Chỉ admin được xem template nháp.', 403);
        }
        if (!in_array($name, ['overlay.png', 'background.png', 'preview.jpg'], true) || !isset($item['hashes'][$name])) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy tài nguyên mẫu.', 404);
        }
        return $item['_directory'] . '/' . $name;
    }

    public function renderSnapshot(string $id, string $version): array
    {
        $item = $this->resolve($id, $version);
        $layout = $this->layout($item['layout_id'], $item['layout_version']);
        $layout['id'] = $id;
        $layout['version'] = $version;
        $layout['layout_id'] = $item['layout_id'];
        $layout['layout_version'] = $item['layout_version'];
        $layout['overlay_path'] = $item['_directory'] . '/overlay.png';
        $layout['background_path'] = isset($item['background']) ? $item['_directory'] . '/background.png' : null;
        $layout['resource_hashes'] = $item['hashes'];
        if (isset($item['text_slots'])) {
            $layout['text_slots'] = $item['text_slots'];
        }
        return $layout;
    }

    public function importZip(string $bytes, string $actorId, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $payloadHash = hash('sha256', $bytes);
        $replay = IdempotencyService::checkReplay($this->db->pdo(), $actorId, 'template.import', $key, $payloadHash);
        if ($replay !== null) {
            return $replay['response'];
        }
        if ($bytes === '' || strlen($bytes) > 30 * 1024 * 1024) {
            throw new ApiException('INVALID_PACKAGE', 'Gói ZIP phải có dung lượng tối đa 30 MiB.', 422);
        }
        $staging = $this->config->dataDir . '/templates/.import-' . Uuid::v4();
        if (!mkdir($staging, 0750, true)) {
            throw new ApiException('STORAGE_ERROR', 'Không tạo được vùng nhập template.', 500);
        }
        $zipPath = $staging . '/upload.zip';
        file_put_contents($zipPath, $bytes);
        $zip = new ZipArchive();
        $zipOpen = false;
        $destination = null;
        $published = false;
        try {
            $zipOpen = $zip->open($zipPath) === true;
            if (!$zipOpen || $zip->numFiles > 20) {
                throw new ApiException('INVALID_PACKAGE', 'ZIP không hợp lệ hoặc có quá 20 entry.', 422);
            }
            $total = 0;
            $seen = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                $opsys = 0;
                $attributes = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if (!in_array($name, ['template.json', 'overlay.png', 'background.png', 'preview.jpg'], true)
                    || isset($seen[$name]) || (($attributes >> 16) & 0170000) === 0120000) {
                    throw new ApiException('INVALID_PACKAGE', 'ZIP chứa đường dẫn hoặc loại tệp không được phép.', 422);
                }
                $seen[$name] = true;
                $total += (int) $stat['size'];
                if ($total > 60 * 1024 * 1024 || ($name === 'template.json' && $stat['size'] > 65536)) {
                    throw new ApiException('INVALID_PACKAGE', 'Dữ liệu giải nén vượt giới hạn.', 422);
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new ApiException('INVALID_PACKAGE', 'Không đọc được entry ZIP.', 422);
                }
                $content = stream_get_contents($stream, min(60 * 1024 * 1024 + 1, (int) $stat['size'] + 1));
                fclose($stream);
                if ($content === false || strlen($content) !== (int) $stat['size']) {
                    throw new ApiException('INVALID_PACKAGE', 'Kích thước entry ZIP không khớp.', 422);
                }
                file_put_contents($staging . '/' . $name, $content);
            }
            $zip->close();
            $zipOpen = false;
            unlink($zipPath);
            $item = $this->validatePackage($staging);
            $destination = $this->config->dataDir . '/templates/' . $item['id'] . '/' . $item['version'];
            return $this->db->transaction(function (PDO $pdo) use ($item, $destination, $staging, $actorId, $key, $payloadHash, &$published): array {
                $replay = IdempotencyService::checkReplay($pdo, $actorId, 'template.import', $key, $payloadHash);
                if ($replay !== null) {
                    return $replay['response'];
                }
                $existing = $pdo->prepare('SELECT 1 FROM template_catalog WHERE id = :id AND version = :version');
                $existing->execute([':id' => $item['id'], ':version' => $item['version']]);
                if ($existing->fetchColumn() !== false || isset($this->bundled()[$item['id'] . '/' . $item['version']]) || file_exists($destination)) {
                    throw new ApiException('TEMPLATE_VERSION_EXISTS', 'Phiên bản đã tồn tại. Hãy tăng version.', 409);
                }
                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0750, true);
                }
                if (!rename($staging, $destination)) {
                    throw new ApiException('STORAGE_ERROR', 'Không xuất bản được template.', 500);
                }
                $published = true;
                $now = Clock::nowIso();
                $pdo->prepare('INSERT INTO template_catalog (id, version, status, manifest, created_at, updated_at) VALUES (:id,:version,\'draft\',:manifest,:now,:now)')
                    ->execute([':id' => $item['id'], ':version' => $item['version'], ':manifest' => json_encode($item), ':now' => $now]);
                $response = $this->publicItem($item + ['status' => 'draft']);
                IdempotencyService::record($pdo, $actorId, 'template.import', $key, null, $payloadHash, $item['id'], 201, $response);
                AuditLogger::record($pdo, 'template.import', 'DRAFT', $actorId, null, null, ['id' => $item['id'], 'version' => $item['version']]);
                return $response;
            });
        } catch (Throwable $e) {
            if ($published && $destination !== null) {
                $this->removeImportDirectory($destination);
            }
            throw $e;
        } finally {
            if ($zipOpen) { $zip->close(); }
            $this->removeImportDirectory($staging);
        }
    }

    private function validatePackage(string $directory): array
    {
        $item = json_decode((string) @file_get_contents($directory . '/template.json'), true);
        if (!is_array($item)) {
            throw new ApiException('INVALID_PACKAGE', 'Thiếu template.json hợp lệ.', 422);
        }
        foreach (['id', 'name', 'version', 'layout_id', 'layout_version', 'overlay', 'preview'] as $field) {
            if (!isset($item[$field]) || !is_string($item[$field])) {
                throw new ApiException('INVALID_PACKAGE', 'Thiếu trường template: ' . $field, 422);
            }
        }
        $this->validateIdentity($item['id'], $item['version']);
        $this->layout($item['layout_id'], $item['layout_version']);
        if (trim($item['name']) === '' || mb_strlen($item['name']) > 100 || $item['overlay'] !== 'overlay.png' || $item['preview'] !== 'preview.jpg'
            || (isset($item['background']) && $item['background'] !== 'background.png')) {
            throw new ApiException('INVALID_PACKAGE', 'Tên hoặc đường dẫn tài nguyên template không hợp lệ.', 422);
        }
        $clean = array_intersect_key($item, array_flip(['id','name','version','layout_id','layout_version','overlay','background','preview','style']));
        $clean['style'] = isset($item['style']) && is_string($item['style']) ? $item['style'] : $item['id'];
        $this->validateIdentity($clean['style'], '1.0.0');
        if (is_file($directory . '/background.png') && !isset($clean['background'])) {
            throw new ApiException('INVALID_PACKAGE', 'background.png phải được khai báo trong manifest.', 422);
        }
        $clean['hashes'] = [];
        foreach (['overlay.png', 'preview.jpg', 'background.png'] as $name) {
            $path = $directory . '/' . $name;
            if (!is_file($path) && $name === 'background.png' && !isset($clean['background'])) {
                continue;
            }
            $info = @getimagesize($path);
            $expectedType = $name === 'preview.jpg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
            if ($info === false || $info[2] !== $expectedType || ($name !== 'preview.jpg' && [$info[0], $info[1]] !== [1181, 1748])
                || ($name === 'preview.jpg' && (max($info[0], $info[1]) > 1000 || min($info[0], $info[1]) < 1))) {
                throw new ApiException('INVALID_PACKAGE', 'Sai định dạng hoặc kích thước: ' . $name, 422);
            }
            $decoded = $name === 'preview.jpg' ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
            if ($decoded === false) {
                throw new ApiException('INVALID_PACKAGE', 'Không giải mã được ảnh: ' . $name, 422);
            }
            imagedestroy($decoded);
            if ($name === 'overlay.png') {
                $header = file_get_contents($path, false, null, 0, 26);
                if (strlen($header) < 26 || ord($header[25]) !== 6) {
                    throw new ApiException('INVALID_PACKAGE', 'Overlay phải là PNG RGBA.', 422);
                }
            }
            $clean['hashes'][$name] = hash_file('sha256', $path);
        }
        return $clean;
    }

    public function setStatus(string $id, string $version, string $status, string $actorId, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $hash = IdempotencyService::hashPayload(['id' => $id, 'version' => $version, 'status' => $status]);
        return $this->db->transaction(function (PDO $pdo) use ($id, $version, $status, $actorId, $key, $hash): array {
            $replay = IdempotencyService::checkReplay($pdo, $actorId, 'template.status', $key, $hash);
            if ($replay !== null) {
                return $replay['response'];
            }
            $item = $this->resolve($id, $version);
            unset($item['_directory'], $item['preview_url'], $item['overlay_url'], $item['background_url'], $item['status']);
            $now = Clock::nowIso();
            $pdo->prepare('INSERT INTO template_catalog (id,version,status,manifest,created_at,updated_at) VALUES (:id,:v,:s,:m,:now,:now) ON CONFLICT(id,version) DO UPDATE SET status=excluded.status,updated_at=excluded.updated_at')
                ->execute([':id' => $id, ':v' => $version, ':s' => $status, ':m' => json_encode($item), ':now' => $now]);
            $response = $this->publicItem($item + ['status' => $status]);
            IdempotencyService::record($pdo, $actorId, 'template.status', $key, null, $hash, $id, 200, $response);
            AuditLogger::record($pdo, 'template.status', strtoupper($status), $actorId, null, null, ['id' => $id, 'version' => $version]);
            return $response;
        });
    }

    private function validateIdentity(string $id, string $version): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $id) !== 1 || preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D', $version) !== 1) {
            throw new ApiException('INVALID_PACKAGE', 'ID hoặc version không hợp lệ.', 422);
        }
    }

    private function removeImportDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($directory . '/' . $name);
            }
        }
        rmdir($directory);
    }
}
