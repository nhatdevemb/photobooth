<?php

declare(strict_types=1);

namespace Photobooth\Operator\Catalog;

use PDO;
use Throwable;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Session\IdempotencyService;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;

final class LutCatalog
{
    private AppConfig $config;
    private Database $db;
    private Renderer $renderer;

    public function __construct(AppConfig $config, Database $db, Renderer $renderer)
    {
        $this->config = $config;
        $this->db = $db;
        $this->renderer = $renderer;
    }

    private function records(): array
    {
        $items = [];
        $names = ['original'=>'Gốc', 'korean-soft'=>'Hàn Quốc', 'film-400'=>'Film', 'vintage-fade'=>'Vintage'];
        foreach ($this->config->getPresetsMap() as $preset) {
            $preset['name'] = $names[$preset['id']] ?? $preset['name'];
            $preset['status'] = 'active';
            $preset['intensity'] = 100;
            $preset['revision'] = 1;
            $preset['readonly'] = true;
            $preset['sample_url'] = '/custom/assets/samples/' . $preset['id'] . '.jpg';
            $items[] = $preset;
        }
        foreach ($this->db->pdo()->query('SELECT * FROM lut_catalog ORDER BY created_at,id,version')->fetchAll() as $item) {
            $item['readonly'] = false;
            $item['intensity'] = (int) $item['intensity'];
            $item['revision'] = (int) $item['revision'];
            $item['size'] = 33;
            $item['type'] = 'cube_3d';
            $item['sample_url'] = '/custom/api/catalog/luts/' . $item['id'] . '/' . $item['version'] . '/preview.jpg?sha=' . $item['preview_sha256'];
            $items[] = $item;
        }
        return $items;
    }

    private function present(array $item): array
    {
        unset($item['file_path'], $item['preview_path']);
        return $item;
    }

    public function items(bool $admin = false): array
    {
        return array_values(array_map(fn (array $item): array => $this->present($item), array_filter($this->records(), static fn (array $item): bool => $admin || $item['status'] === 'active')));
    }

    public function resolve(string $id, ?string $version = null, bool $active = false): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $id) || ($version !== null && !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D', $version))) {
            throw new ApiException('INVALID_LUT', 'ID hoặc phiên bản filter không hợp lệ.', 422);
        }
        $choices = array_values(array_filter($this->records(), static fn (array $item): bool => $item['id'] === $id && ($version === null || $item['version'] === $version) && (!$active || $item['status'] === 'active')));
        usort($choices, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        if ($choices === []) {
            throw new ApiException('INVALID_LUT', 'Filter không tồn tại hoặc chưa được kích hoạt.', 422);
        }
        $item = $choices[0];
        $path = $this->filePath($item);
        if ($path !== null && (!is_file($path) || !hash_equals($item['sha256'], hash_file('sha256', $path) ?: ''))) {
            throw new ApiException('INTEGRITY_ERROR', 'File LUT đã bị thay đổi.', 409);
        }
        return $item;
    }

    public function describe(string $id, string $version): array
    {
        return $this->present($this->resolve($id, $version));
    }

    public function filePath(array $item): ?string
    {
        if ($item['readonly']) {
            return empty($item['file']) ? null : $this->config->customRoot . '/presets/' . $item['file'];
        }
        return $this->config->dataDir . '/' . $item['file_path'];
    }

    public function cubePath(string $id, string $version, bool $admin): string
    {
        $item = $this->resolve($id, $version);
        if ($item['status'] === 'draft' && !$admin) {
            throw new ApiException('FORBIDDEN', 'Chỉ admin được xem filter nháp.', 403);
        }
        $path = $this->filePath($item);
        if ($path === null || !is_file($path)) {
            throw new ApiException('NOT_FOUND', 'Filter gốc không có file .cube.', 404);
        }
        return $path;
    }

    public function samplePath(string $id, string $version, bool $admin, ?string $hash = null): string
    {
        $item = $this->resolve($id, $version);
        if ($item['status'] === 'draft' && !$admin) {
            throw new ApiException('FORBIDDEN', 'Chỉ admin được xem filter nháp.', 403);
        }
        if ($item['readonly']) {
            return $this->config->customRoot . '/public/assets/samples/' . $id . '.jpg';
        }
        if ($hash !== null && !hash_equals($item['preview_sha256'], $hash)) {
            throw new ApiException('PREVIEW_STALE', 'Bản xem trước đã thay đổi.', 409);
        }
        $path = $this->config->dataDir . '/' . $item['preview_path'];
        if (!is_file($path) || !hash_equals($item['preview_sha256'], hash_file('sha256', $path) ?: '')) {
            throw new ApiException('INTEGRITY_ERROR', 'Ảnh mẫu LUT đã bị thay đổi.', 409);
        }
        return $path;
    }

    public function import(array $upload, string $actor, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $name = $upload['name'] ?? '';
        $bytes = $this->uploadBytes($upload);
        $hash = IdempotencyService::hashPayload(['filename'=>$name, 'sha256'=>hash('sha256', $bytes)]);
        $replay = IdempotencyService::checkReplay($this->db->pdo(), $actor, 'lut.import', $key, $hash);
        if ($replay !== null) {
            return $replay['response'];
        }
        $stage = $this->staging();
        try {
            file_put_contents($stage . '/filter.cube', $bytes);
            $result = $this->renderer->previewLut($stage . '/filter.cube', $stage . '/sample.jpg', 80);
            $title = trim((string) ($result['title'] ?? ''));
            // The parser's staging filename is not a user-visible title.
            if ($title === '' || $title === 'filter') {
                $title = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
            }
            $title = mb_substr($title, 0, 100);
            return $this->publish($stage, 'lut-' . Uuid::v4(), null, $title, 80, $result, $actor, 'lut.import', $key, $hash, true);
        } finally {
            $this->removeStage($stage);
        }
    }

    public function createVersion(string $id, string $version, int $revision, string $actor, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $hash = IdempotencyService::hashPayload(['id'=>$id, 'version'=>$version, 'revision'=>$revision]);
        $replay = IdempotencyService::checkReplay($this->db->pdo(), $actor, 'lut.version', $key, $hash);
        if ($replay !== null) {
            return $replay['response'];
        }
        $item = $this->resolve($id, $version);
        $this->assertMutable($item, $revision);
        if ($item['status'] === 'draft') {
            throw new ApiException('INVALID_STATE', 'Filter này đã là bản nháp.', 422);
        }
        $stage = $this->staging();
        try {
            copy($this->filePath($item), $stage . '/filter.cube');
            copy($this->samplePath($id, $version, true), $stage . '/sample.jpg');
            return $this->publish($stage, $id, $version, $item['name'], $item['intensity'], $item, $actor, 'lut.version', $key, $hash, false);
        } finally {
            $this->removeStage($stage);
        }
    }

    private function publish(string $stage, string $id, ?string $sourceVersion, string $name, int $intensity, array $meta, string $actor, string $operation, string $key, string $hash, bool $deduplicate): array
    {
        $destination = null;
        try {
            return $this->db->transaction(function (PDO $pdo) use ($stage, $id, $sourceVersion, $name, $intensity, $meta, $actor, $operation, $key, $hash, $deduplicate, &$destination): array {
                $replay = IdempotencyService::checkReplay($pdo, $actor, $operation, $key, $hash);
                if ($replay !== null) {
                    return $replay['response'];
                }
                $version = '1.0.0';
                foreach ($this->records() as $existing) {
                    if ($deduplicate && $existing['sha256'] === $meta['sha256']) {
                        throw new ApiException('LUT_ALREADY_IMPORTED', 'File này đã tồn tại trong thư viện.', 409, ['lut_id'=>$existing['id'], 'lut_version'=>$existing['version']]);
                    }
                    if (!$deduplicate && $existing['id'] === $id) {
                        if ($existing['status'] === 'draft') {
                            throw new ApiException('LUT_DRAFT_EXISTS', 'Hãy chỉnh bản nháp đang có của filter này.', 409);
                        }
                        if (version_compare($existing['version'], $version) >= 0) {
                            $parts = explode('.', $existing['version']);
                            $parts[2] = (string) ((int) $parts[2] + 1);
                            if ((int) $parts[2] > 999) {
                                throw new ApiException('VERSION_LIMIT', 'Đã đạt giới hạn phiên bản filter.', 409);
                            }
                            $version = implode('.', $parts);
                        }
                    }
                }
                if ($sourceVersion !== null) {
                    $source = $this->resolve($id, $sourceVersion);
                    if ($source['sha256'] !== $meta['sha256'] || $source['revision'] !== $meta['revision']) {
                        throw new ApiException('REVISION_CONFLICT', 'Filter vừa được cập nhật. Hãy tải lại.', 409);
                    }
                }
                $relative = 'luts/' . $id . '/' . $version;
                $target = $this->config->dataDir . '/' . $relative;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0750, true);
                }
                $previewName = 'preview-' . $meta['preview_sha256'] . '.jpg';
                rename($stage . '/sample.jpg', $stage . '/' . $previewName);
                if (file_exists($target) || !rename($stage, $target)) {
                    throw new ApiException('STORAGE_ERROR', 'Không thể xuất bản bản nháp LUT.', 500);
                }
                $destination = $target;
                $now = Clock::nowIso();
                $pdo->prepare("INSERT INTO lut_catalog (id,version,name,status,sha256,intensity,file_path,preview_path,preview_sha256,created_at,updated_at) VALUES (:id,:v,:name,'draft',:sha,:amount,:file,:preview,:psha,:now,:now)")
                    ->execute([':id'=>$id, ':v'=>$version, ':name'=>$name, ':sha'=>$meta['sha256'], ':amount'=>$intensity, ':file'=>$relative . '/filter.cube', ':preview'=>$relative . '/' . $previewName, ':psha'=>$meta['preview_sha256'], ':now'=>$now]);
                $response = $this->describe($id, $version);
                IdempotencyService::record($pdo, $actor, $operation, $key, null, $hash, $id, 201, $response);
                AuditLogger::record($pdo, $operation, 'DRAFT', $actor, null, null, ['id'=>$id, 'version'=>$version, 'sha256'=>$meta['sha256']]);
                return $response;
            });
        } catch (Throwable $error) {
            if ($destination !== null) {
                $this->removeStage($destination);
            }
            throw $error;
        }
    }

    public function editDraft(string $id, string $version, int $revision, array $payload, string $actor, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $signedPayload = $payload;
        $replacement = null;
        if (isset($payload['file'])) {
            if (!is_array($payload['file'])) {
                throw new ApiException('INVALID_LUT_UPLOAD', 'File LUT không hợp lệ.', 422);
            }
            $replacement = $this->uploadBytes($payload['file']);
            $signedPayload['file'] = ['name'=>$payload['file']['name'], 'sha256'=>hash('sha256', $replacement)];
        }
        $hash = IdempotencyService::hashPayload(['id'=>$id, 'version'=>$version, 'payload'=>$signedPayload]);
        $replay = IdempotencyService::checkReplay($this->db->pdo(), $actor, 'lut.edit', $key, $hash);
        if ($replay !== null) {
            return $replay['response'];
        }
        $item = $this->resolve($id, $version);
        $this->assertMutable($item, $revision);
        $name = $payload['name'] ?? $item['name'];
        $amount = $payload['intensity'] ?? $item['intensity'];
        if ($item['status'] !== 'draft') {
            throw new ApiException('LUT_IMMUTABLE', 'Tạo phiên bản nháp mới để thay đổi filter đã xuất bản.', 409);
        }
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || trim($name) === '' || mb_strlen($name) > 100 || !is_int($amount) || $amount < 0 || $amount > 100) {
            throw new ApiException('INVALID_INPUT', 'Tên tối đa 100 ký tự; cường độ phải là số nguyên 0–100.', 422);
        }
        $stage = $this->staging();
        try {
            $lutPath = $this->filePath($item);
            if ($replacement !== null) {
                $lutPath = $stage . '/filter.cube';
                file_put_contents($lutPath, $replacement);
            }
            $result = $this->renderer->previewLut($lutPath, $stage . '/sample.jpg', $amount);
            return $this->db->transaction(function (PDO $pdo) use ($id, $version, $revision, $name, $amount, $stage, $result, $item, $actor, $key, $hash, $replacement): array {
                $replay = IdempotencyService::checkReplay($pdo, $actor, 'lut.edit', $key, $hash);
                if ($replay !== null) {
                    return $replay['response'];
                }
                $current = $this->resolve($id, $version);
                $this->assertMutable($current, $revision);
                if ($current['status'] !== 'draft') {
                    throw new ApiException('LUT_IMMUTABLE', 'Bản nháp đã được xuất bản.', 409);
                }
                $relative = dirname($item['file_path']) . '/preview-' . $result['preview_sha256'] . '.jpg';
                $target = $this->config->dataDir . '/' . $relative;
                if (!is_file($target) && !rename($stage . '/sample.jpg', $target)) {
                    throw new ApiException('STORAGE_ERROR', 'Không thể lưu ảnh mẫu LUT.', 500);
                }
                $file = $item['file_path'];
                if ($replacement !== null) {
                    $file = dirname($file) . '/filter-' . $result['sha256'] . '.cube';
                    if (!is_file($this->config->dataDir . '/' . $file) && !rename($stage . '/filter.cube', $this->config->dataDir . '/' . $file)) {
                        throw new ApiException('STORAGE_ERROR', 'Không thể lưu file LUT.', 500);
                    }
                }
                $pdo->prepare('UPDATE lut_catalog SET name=:name,intensity=:amount,sha256=:lutsha,file_path=:file,preview_path=:path,preview_sha256=:sha,revision=revision+1,updated_at=:now WHERE id=:id AND version=:v')
                    ->execute([':name'=>trim($name), ':amount'=>$amount, ':lutsha'=>$result['sha256'], ':file'=>$file, ':path'=>$relative, ':sha'=>$result['preview_sha256'], ':now'=>Clock::nowIso(), ':id'=>$id, ':v'=>$version]);
                $response = $this->describe($id, $version);
                IdempotencyService::record($pdo, $actor, 'lut.edit', $key, null, $hash, $id, 200, $response);
                AuditLogger::record($pdo, 'lut.edit', 'DRAFT', $actor, null, null, ['id'=>$id, 'version'=>$version, 'intensity'=>$amount]);
                return $response;
            });
        } finally {
            $this->removeStage($stage);
        }
    }

    public function setStatus(string $id, string $version, int $revision, string $status, ?string $previewHash, string $actor, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $hash = IdempotencyService::hashPayload(['id'=>$id, 'version'=>$version, 'revision'=>$revision, 'status'=>$status, 'preview'=>$previewHash]);
        return $this->db->transaction(function (PDO $pdo) use ($id, $version, $revision, $status, $previewHash, $actor, $key, $hash): array {
            $replay = IdempotencyService::checkReplay($pdo, $actor, 'lut.status', $key, $hash);
            if ($replay !== null) {
                return $replay['response'];
            }
            $item = $this->resolve($id, $version);
            $this->assertMutable($item, $revision);
            if ($status === 'active') {
                if ($previewHash === null || !hash_equals($item['preview_sha256'], $previewHash)) {
                    throw new ApiException('PREVIEW_STALE', 'Hãy xem đúng ảnh mẫu hiện tại trước khi kích hoạt.', 409);
                }
                $this->samplePath($id, $version, true, $previewHash);
                $pdo->prepare("UPDATE lut_catalog SET status='disabled',revision=revision+1,updated_at=:now WHERE id=:id AND status='active' AND version<>:v")
                    ->execute([':now'=>Clock::nowIso(), ':id'=>$id, ':v'=>$version]);
            }
            $pdo->prepare('UPDATE lut_catalog SET status=:status,revision=revision+1,updated_at=:now WHERE id=:id AND version=:v')
                ->execute([':status'=>$status, ':now'=>Clock::nowIso(), ':id'=>$id, ':v'=>$version]);
            $response = $this->describe($id, $version);
            IdempotencyService::record($pdo, $actor, 'lut.status', $key, null, $hash, $id, 200, $response);
            AuditLogger::record($pdo, 'lut.status', strtoupper($status), $actor, null, null, ['id'=>$id, 'version'=>$version]);
            return $response;
        });
    }

    private function assertMutable(array $item, int $revision): void
    {
        if ($item['readonly']) {
            throw new ApiException('LUT_IMMUTABLE', 'Bốn filter đi kèm được giữ nguyên; hãy nhập một LUT mới.', 409);
        }
        if ($item['revision'] !== $revision) {
            throw new ApiException('REVISION_CONFLICT', 'Filter đã thay đổi. Hãy tải lại thư viện.', 409);
        }
    }

    private function uploadBytes(array $upload): string
    {
        $name = $upload['name'] ?? '';
        $bytes = $upload['content'] ?? null;
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || strlen($name) > 255 || preg_match('/[\x00-\x1f\x7f\\\\\/]/', $name) || pathinfo($name, PATHINFO_FILENAME) === '' || !is_string($bytes) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024 || !preg_match('/\.cube$/iD', $name)) {
            throw new ApiException('INVALID_LUT_UPLOAD', 'Chọn file .cube UTF-8 tối đa 5 MiB.', 422);
        }
        return $bytes;
    }

    private function staging(): string
    {
        $path = $this->config->dataDir . '/luts/.import-' . Uuid::v4();
        if (!mkdir($path, 0750, true)) {
            throw new ApiException('STORAGE_ERROR', 'Không tạo được thư mục LUT tạm.', 500);
        }
        return $path;
    }

    private function removeStage(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
