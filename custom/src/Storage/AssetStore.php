<?php

declare(strict_types=1);

namespace Photobooth\Operator\Storage;

use PDO;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Uuid;

final class AssetStore
{
    private AppConfig $config;
    private StructuredLogger $logger;

    public function __construct(AppConfig $config, StructuredLogger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->ensureDirectories();
    }

    public function ensureDirectories(): void
    {
        foreach (['sessions', 'prints/receipts', 'locks', 'logs', 'quarantine'] as $sub) {
            $dir = $this->config->dataDir . '/' . $sub;
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }
    }

    public function getFreeDiskBytes(): int
    {
        if ($this->config->mockDiskFreeBytes !== null) {
            return $this->config->mockDiskFreeBytes;
        }
        if ($this->config->mockFaultScenario === 'disk_low') {
            return 512 * 1024 * 1024; // 512 MiB (< 2 GiB threshold)
        }
        $free = @disk_free_space($this->config->dataDir);
        return $free === false ? 0 : (int) $free;
    }

    public function isDiskLow(): bool
    {
        return $this->getFreeDiskBytes() < $this->config->diskLowBytes;
    }

    public function assertDiskSpaceAvailable(): void
    {
        if ($this->isDiskLow()) {
            $this->logger->error('disk_low_blocked', 'DISK_LOW', [
                'free_bytes' => $this->getFreeDiskBytes(),
                'threshold_bytes' => $this->config->diskLowBytes,
            ]);
            throw new ApiException(
                'DISK_LOW',
                'Dung lượng ổ đĩa trống dưới ngưỡng an toàn (2 GiB). Vui lòng dọn dữ liệu hoặc chạy bảo trì trước khi chụp/xử lý/in mới.',
                503
            );
        }
    }

    public function sessionDir(string $sessionId): string
    {
        $this->assertValidUuid($sessionId, 'session_id');
        return $this->config->dataDir . '/sessions/' . $sessionId;
    }

    public function pendingJobDir(string $sessionId, string $jobId, int $attempt = 1): string
    {
        $this->assertValidUuid($sessionId, 'session_id');
        $this->assertValidUuid($jobId, 'job_id');
        $dir = $this->sessionDir($sessionId) . '/pending/' . $jobId . '/attempt-' . max(1, $attempt);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public function originalPhotoRelativePath(string $sessionId, int $slot, int $version): string
    {
        $this->assertValidUuid($sessionId, 'session_id');
        if ($slot < 1 || $slot > 4) {
            throw new ApiException('INVALID_SLOT', 'Slot ảnh phải từ 1 đến 4.', 422);
        }
        if ($version < 1) {
            throw new ApiException('INVALID_VERSION', 'Phiên bản ảnh không hợp lệ.', 422);
        }
        return sprintf('sessions/%s/originals/slot-%d/v%d.jpg', $sessionId, $slot, $version);
    }

    public function renderPrintRelativePath(string $sessionId, string $renderId): string
    {
        $this->assertValidUuid($sessionId, 'session_id');
        $this->assertValidUuid($renderId, 'render_id');
        return sprintf('sessions/%s/rendered/%s/print.jpg', $sessionId, $renderId);
    }

    public function renderPreviewRelativePath(string $sessionId, string $renderId): string
    {
        $this->assertValidUuid($sessionId, 'session_id');
        $this->assertValidUuid($renderId, 'render_id');
        return sprintf('sessions/%s/rendered/%s/preview.jpg', $sessionId, $renderId);
    }

    public function finalizedAssetRelativePath(string $sessionId, string $finalAssetId): string
    {
        $this->assertValidUuid($sessionId, 'session_id');
        $this->assertValidUuid($finalAssetId, 'final_asset_id');
        return sprintf('sessions/%s/finalized/%s.jpg', $sessionId, $finalAssetId);
    }

    public function absolutePath(string $relativePath): string
    {
        if (
            str_contains($relativePath, '..')
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, "\0")
            || preg_match('#^sessions/[0-9a-f-]+/(originals/slot-[1234]/v\d+\.jpg|rendered/[0-9a-f-]+/(print|preview)\.jpg|finalized/[0-9a-f-]+\.jpg)$#i', $relativePath) !== 1
        ) {
            throw new ApiException('FORBIDDEN', 'Đường dẫn tài nguyên không hợp lệ.', 403);
        }
        return $this->config->dataDir . '/' . $relativePath;
    }

    /**
     * Atomically publish a pending file to its immutable relative destination.
     * Uses temp file on the same filesystem, fflush/fsync, and atomic rename.
     */
    public function publishImmutableFile(string $sourceFile, string $targetRelativePath): string
    {
        if (!is_file($sourceFile)) {
            throw new ApiException('STORAGE_ERROR', 'Không tìm thấy tệp tạm để lưu trữ.', 500);
        }

        $targetAbs = $this->absolutePath($targetRelativePath);
        if (is_file($targetAbs)) {
            throw new ApiException('IMMUTABLE_CONFLICT', 'Tài sản bất biến đã tồn tại, không được phép ghi đè.', 409);
        }

        $targetDir = dirname($targetAbs);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
            throw new ApiException('STORAGE_ERROR', 'Không thể tạo thư mục lưu ảnh.', 500);
        }

        $tmpTarget = $targetDir . '/.' . basename($targetAbs) . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $bytes = @file_get_contents($sourceFile);
        if ($bytes === false || $bytes === '') {
            throw new ApiException('STORAGE_ERROR', 'Không thể đọc dữ liệu tệp nguồn.', 500);
        }

        $fp = @fopen($tmpTarget, 'wb');
        if ($fp === false) {
            throw new ApiException('STORAGE_ERROR', 'Không thể mở tệp đích tạm thời.', 500);
        }

        try {
            $written = fwrite($fp, $bytes);
            if ($written === false || $written !== strlen($bytes)) {
                throw new ApiException('STORAGE_ERROR', 'Ghi tệp ảnh chưa hoàn tất.', 500);
            }
            fflush($fp);
            if (function_exists('fsync')) {
                @fsync($fp);
            }
        } finally {
            fclose($fp);
        }

        if (!@rename($tmpTarget, $targetAbs)) {
            @unlink($tmpTarget);
            throw new ApiException('STORAGE_ERROR', 'Không thể đổi tên nguyên tử tệp ảnh.', 500);
        }

        $this->fsyncDirectory($targetDir);
        return hash('sha256', $bytes);
    }

    /**
     * Copy exact bytes from approved render print.jpg to finalized/{final_asset_id}.jpg
     * without re-encoding, verifying SHA-256 before and after.
     */
    public function publishFinalizedFromRender(
        string $renderPrintRelativePath,
        string $expectedSha256,
        string $finalRelativePath
    ): string
    {
        $renderAbs = $this->absolutePath($renderPrintRelativePath);
        if (!is_file($renderAbs)) {
            throw new ApiException('INTEGRITY_ERROR', 'Tệp render gốc không tồn tại trên ổ đĩa.', 409);
        }

        $actualRenderHash = hash_file('sha256', $renderAbs) ?: '';
        if (!hash_equals($expectedSha256, $actualRenderHash)) {
            $this->logger->error('render_hash_mismatch_on_finalize', 'HASH_MISMATCH', [
                'path' => $renderPrintRelativePath,
                'expected_sha256' => $expectedSha256,
                'actual_sha256' => $actualRenderHash,
            ]);
            throw new ApiException('INTEGRITY_ERROR', 'Mã băm SHA-256 của bản render không khớp.', 409);
        }

        $publishedHash = $this->publishImmutableFile($renderAbs, $finalRelativePath);
        if (!hash_equals($expectedSha256, $publishedHash)) {
            throw new ApiException('INTEGRITY_ERROR', 'Mã băm SHA-256 của ảnh cuối không khớp sau khi sao chép.', 500);
        }
        return $publishedHash;
    }

    /**
     * Verify that an immutable asset exists and matches its recorded SHA-256.
     */
    public function verifyAssetIntegrity(string $relativePath, string $expectedSha256, ?PDO $pdo = null, ?string $sessionId = null): string
    {
        $absPath = $this->absolutePath($relativePath);
        if (!is_file($absPath)) {
            $this->logger->error('asset_file_missing', 'HASH_MISMATCH', [
                'session_id' => $sessionId,
                'path' => $relativePath,
            ]);
            if ($pdo !== null) {
                AuditLogger::record($pdo, 'asset.integrity_check', 'FAILED_MISSING_FILE', null, $sessionId, null, [
                    'path' => $relativePath,
                ]);
            }
            throw new ApiException(
                'INTEGRITY_ERROR',
                'Tệp ảnh cuối bị thiếu trên ổ đĩa. Đã chặn thao tác để bảo vệ toàn vẹn dữ liệu.',
                409
            );
        }

        $actualHash = hash_file('sha256', $absPath) ?: '';
        if (!hash_equals($expectedSha256, $actualHash)) {
            $this->logger->error('asset_hash_mismatch', 'HASH_MISMATCH', [
                'session_id' => $sessionId,
                'path' => $relativePath,
                'expected' => $expectedSha256,
                'actual' => $actualHash,
            ]);
            if ($pdo !== null) {
                AuditLogger::record($pdo, 'asset.integrity_check', 'FAILED_HASH_MISMATCH', null, $sessionId, null, [
                    'path' => $relativePath,
                    'expected_sha256' => $expectedSha256,
                    'actual_sha256' => $actualHash,
                ]);
            }
            throw new ApiException(
                'INTEGRITY_ERROR',
                'Dữ liệu tệp ảnh không khớp mã kiểm tra SHA-256. Đã chặn tải/in tài sản này.',
                409
            );
        }

        return $absPath;
    }

    /** Move only a failed attempt's uncommitted photo, while holding the database write lock. */
    public function quarantineUnreferencedPhoto(PDO $pdo, string $relativePath): void
    {
        $absolutePath = $this->absolutePath($relativePath);
        $stmt = $pdo->prepare('SELECT 1 FROM photos WHERE path = :path');
        $stmt->execute([':path' => $relativePath]);
        if ($stmt->fetchColumn() !== false || !is_file($absolutePath)) {
            return;
        }

        $target = $this->config->dataDir . '/quarantine/'
            . str_replace('/', '_', $relativePath) . '.' . bin2hex(random_bytes(8));
        if (!rename($absolutePath, $target)) {
            throw new ApiException('STORAGE_ERROR', 'Không thể cách ly ảnh chưa được ghi nhận.', 500);
        }
        $this->fsyncDirectory(dirname($absolutePath));
        $this->fsyncDirectory(dirname($target));
        $this->logger->warning('capture_orphan_quarantined', 'ORPHAN_QUARANTINED', ['path' => $relativePath]);
    }

    /**
     * Clean pending directories and isolate files not referenced in SQLite at startup.
     * @return array{cleaned_pending_dirs: int, quarantined_orphans: int}
     */
    public function reconcileStartupOrphans(PDO $pdo): array
    {
        $referenced = [];

        foreach ($pdo->query('SELECT path FROM photos')->fetchAll() as $r) {
            $referenced[(string) $r['path']] = true;
        }
        foreach ($pdo->query('SELECT image_path, preview_path FROM renders')->fetchAll() as $r) {
            $referenced[(string) $r['image_path']] = true;
            $referenced[(string) $r['preview_path']] = true;
        }
        foreach ($pdo->query('SELECT path FROM final_assets')->fetchAll() as $r) {
            $referenced[(string) $r['path']] = true;
        }

        $sessionsRoot = $this->config->dataDir . '/sessions';
        if (!is_dir($sessionsRoot)) {
            return ['cleaned_pending_dirs' => 0, 'quarantined_orphans' => 0];
        }

        $cleanedPending = 0;
        $quarantinedOrphans = 0;

        $sessionDirs = glob($sessionsRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($sessionDirs as $sDir) {
            $pendingDir = $sDir . '/pending';
            if (is_dir($pendingDir)) {
                $this->removeDirectoryRecursive($pendingDir);
                $cleanedPending++;
            }

            // Check originals, rendered, finalized for unreferenced files
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $fullPath = $fileInfo->getPathname();
                $relPath = ltrim(str_replace('\\', '/', substr($fullPath, strlen($this->config->dataDir))), '/');
                if (!isset($referenced[$relPath])) {
                    $quarantineTarget = $this->config->dataDir . '/quarantine/' . str_replace('/', '_', $relPath) . '.' . time();
                    @rename($fullPath, $quarantineTarget);
                    $quarantinedOrphans++;
                }
            }
        }

        return [
            'cleaned_pending_dirs' => $cleanedPending,
            'quarantined_orphans' => $quarantinedOrphans,
        ];
    }

    public function removeSessionDirectory(string $sessionId): void
    {
        $dir = $this->sessionDir($sessionId);
        if (is_dir($dir)) {
            $this->removeDirectoryRecursive($dir);
        }
    }

    public function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function assertValidUuid(string $id, string $fieldName): void
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('NOT_FOUND', "Mã định danh {$fieldName} không hợp lệ.", 404);
        }
    }

    private function fsyncDirectory(string $dirPath): void
    {
        $dh = @fopen($dirPath, 'rb');
        if ($dh !== false) {
            if (function_exists('fsync')) {
                @fsync($dh);
            }
            fclose($dh);
        }
    }
}

