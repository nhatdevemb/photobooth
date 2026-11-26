<?php

declare(strict_types=1);

namespace Photobooth\Operator\Maintenance;

use PDO;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Share\GuestRateLimiter;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;

final class MaintenanceService
{
    private Database $db;
    private AppConfig $config;
    private AssetStore $assetStore;
    private StructuredLogger $logger;
    private ?GuestRateLimiter $guestRateLimiter;

    public function __construct(
        Database $db,
        AppConfig $config,
        AssetStore $assetStore,
        StructuredLogger $logger,
        ?GuestRateLimiter $guestRateLimiter = null
    )
    {
        $this->db = $db;
        $this->config = $config;
        $this->assetStore = $assetStore;
        $this->logger = $logger;
        $this->guestRateLimiter = $guestRateLimiter;
    }

    /**
     * Count expired closed/cancelled sessions whose cleanup is held back by unresolved print requests.
     */
    public function countExpiredSessionsHeldByPrints(): int
    {
        $nowIso = Clock::nowIso();
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM sessions s
             WHERE s.state IN ('CLOSED', 'CANCELLED')
               AND s.deleted_at IS NULL
               AND s.expires_at IS NOT NULL
               AND s.expires_at <= :now
               AND EXISTS (
                   SELECT 1 FROM print_requests pr
                   WHERE pr.session_id = s.id
                     AND pr.status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING', 'UNKNOWN')
               )"
        );
        $stmt->execute([':now' => $nowIso]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Run hourly retention, two-phase deletion completion, 30-day audit cleanup, and log rotation.
     * @param null|callable(string): void $beforeTombstone Test barrier after candidate selection.
     *
     * @return array{
     *   expired_purged: int,
     *   tombstoned_completed: int,
     *   held_by_unresolved_prints: int,
     *   audit_purged: int,
     *   logs_rotated: int
     * }
     */
    public function runMaintenancePass(bool $interruptAfterTombstone = false, ?callable $beforeTombstone = null): array
    {
        $nowUnix = Clock::nowUnix();
        $nowIso = Clock::fromUnixIso($nowUnix);
        $pdo = $this->db->pdo();

        $heldCount = $this->countExpiredSessionsHeldByPrints();
        if ($heldCount > 0) {
            $this->logger->warning('retention_cleanup_held_by_prints', 'CLEANUP_HELD', [
                'held_sessions_count' => $heldCount,
            ]);
        }

        // Phase 1: Mark eligible expired sessions as tombstoned and revoke tokens
        $eligibleStmt = $pdo->prepare(
            "SELECT s.id FROM sessions s
             WHERE s.state IN ('CLOSED', 'CANCELLED')
               AND s.deleted_at IS NULL
               AND s.expires_at IS NOT NULL
               AND s.expires_at <= :now
               AND s.active_job_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM jobs j
                   WHERE j.session_id = s.id AND j.status IN ('QUEUED', 'RUNNING')
               )
               AND NOT EXISTS (
                   SELECT 1 FROM print_requests pr
                   WHERE pr.session_id = s.id
                     AND pr.status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING', 'UNKNOWN')
               )"
        );
        $eligibleStmt->execute([':now' => $nowIso]);
        $eligibleRows = $eligibleStmt->fetchAll();

        $expiredTombstoned = 0;
        foreach ($eligibleRows as $r) {
            $sid = (string) $r['id'];
            // Optional synchronization hook for deterministic concurrency tests, never exposed over HTTP.
            if ($beforeTombstone !== null) {
                $beforeTombstone($sid);
            }
            $tombstoned = $this->db->transaction(static function (PDO $txPdo) use ($sid, $nowIso): bool {
                $update = $txPdo->prepare(
                    "UPDATE sessions SET deleted_at = :now, updated_at = :now
                     WHERE id = :id AND deleted_at IS NULL
                       AND state IN ('CLOSED', 'CANCELLED')
                       AND expires_at IS NOT NULL AND expires_at <= :now
                       AND active_job_id IS NULL
                       AND NOT EXISTS (
                           SELECT 1 FROM jobs WHERE session_id = :id AND status IN ('QUEUED', 'RUNNING')
                       )
                       AND NOT EXISTS (
                           SELECT 1 FROM print_requests WHERE session_id = :id
                             AND status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING', 'UNKNOWN')
                       )"
                );
                $update->execute([':now' => $nowIso, ':id' => $sid]);
                if ($update->rowCount() !== 1) {
                    return false;
                }
                $txPdo->prepare('UPDATE share_tokens SET revoked_at = COALESCE(revoked_at, :now) WHERE session_id = :sid')
                    ->execute([':now' => $nowIso, ':sid' => $sid]);
                AuditLogger::record($txPdo, 'session.retention_tombstoned', 'TOMBSTONED', null, $sid);
                return true;
            });
            if ($tombstoned) {
                $expiredTombstoned++;
            }
        }

        // Fault injection hook for IT-16 (interrupt between phase 1 tombstone and phase 2 file deletion)
        if ($interruptAfterTombstone) {
            return [
                'expired_purged' => 0,
                'tombstoned_completed' => 0,
                'held_by_unresolved_prints' => $heldCount,
                'audit_purged' => 0,
                'logs_rotated' => 0,
            ];
        }

        // Phase 2: Complete deletion for all tombstoned sessions without unresolved prints
        $tombStmt = $pdo->query(
            "SELECT s.id FROM sessions s
             WHERE s.deleted_at IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM print_requests pr
                   WHERE pr.session_id = s.id
                     AND pr.status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING', 'UNKNOWN')
               )"
        );
        $tombRows = $tombStmt !== false ? $tombStmt->fetchAll() : [];

        $tombstonedCompleted = 0;
        foreach ($tombRows as $tr) {
            $sid = (string) $tr['id'];
            $this->assetStore->removeSessionDirectory($sid);
            $this->db->transaction(static function (PDO $txPdo) use ($sid): void {
                AuditLogger::record($txPdo, 'session.retention_purged', 'SUCCEEDED', null, $sid);
                $txPdo->prepare('DELETE FROM sessions WHERE id = :id')->execute([':id' => $sid]);
            });
            $tombstonedCompleted++;
        }

        // Purge expired auth_sessions
        $pdo->prepare('DELETE FROM auth_sessions WHERE expires_at < :now')->execute([':now' => $nowIso]);

        // Purge audit_events older than 30 days
        $auditCutoffIso = Clock::fromUnixIso($nowUnix - ($this->config->auditRetentionDays * 86400));
        $auditDelStmt = $pdo->prepare('DELETE FROM audit_events WHERE created_at < :cutoff');
        $auditDelStmt->execute([':cutoff' => $auditCutoffIso]);
        $auditPurged = $auditDelStmt->rowCount();

        $logStats = $this->logger->rotateLogs();
        $dccPurged = $this->purgeExpiredDccIncoming();
        $guestBucketsPurged = $this->guestRateLimiter !== null
            ? $this->guestRateLimiter->purgeExpiredBuckets($nowUnix)
            : 0;

        return [
            'expired_purged' => $expiredTombstoned,
            'tombstoned_completed' => $tombstonedCompleted,
            'held_by_unresolved_prints' => $heldCount,
            'audit_purged' => $auditPurged,
            'logs_rotated' => $logStats['deleted_files'],
            'dcc_incoming_purged' => $dccPurged,
            'guest_rate_buckets_purged' => $guestBucketsPurged,
        ];
    }

    private function purgeExpiredDccIncoming(): int
    {
        $base = $this->config->dataDir . '/dcc';
        if (!is_dir($base . '/incoming')) { return 0; }
        $lock = @fopen($base . '/camera.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) { fclose($lock); }
            return 0;
        }
        $purged = 0;
        try {
            $cutoff = Clock::nowUnix() - ($this->config->sessionRetentionDays * 86400);
            foreach (glob($base . '/incoming/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $key = basename($directory);
                if (is_link($directory) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || filemtime($directory) > $cutoff) { continue; }
                $receipt = $base . '/' . $key . '.json';
                if (is_file($receipt) && filemtime($receipt) > $cutoff) { continue; }
                // Only the adapter's own single-level incoming folder is eligible.
                $root = str_replace('\\', '/', (string) realpath($base . '/incoming')) . '/';
                $resolved = str_replace('\\', '/', (string) realpath($directory));
                if (!str_starts_with($resolved, $root)) { continue; }
                $this->assetStore->removeDirectoryRecursive($directory);
                if (!is_dir($directory)) { $purged++; }
            }
        } finally { fclose($lock); }
        return $purged;
    }

    /**
     * Create a consistent backup using SQLite VACUUM INTO and asset checksum manifest.
     *
     * @return array{backup_dir: string, sqlite_sha256: string, asset_count: int}
     */
    public function createBackup(string $targetBackupDir): array
    {
        $targetDir = rtrim($targetBackupDir, '/');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
            throw new ApiException('BACKUP_ERROR', 'Không thể tạo thư mục sao lưu.', 500);
        }

        // Check no active running capture/render mutations
        $pdo = $this->db->pdo();
        $runningCount = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'RUNNING'")->fetchColumn();
        if ($runningCount > 0) {
            throw new ApiException(
                'SESSION_BUSY',
                'Đang có tác vụ chụp/render đang chạy. Vui lòng chờ tác vụ hoàn tất trước khi sao lưu.',
                409
            );
        }

        $backupDbPath = $targetDir . '/photobooth.sqlite';
        if (is_file($backupDbPath)) {
            @unlink($backupDbPath);
        }

        $escapedPath = str_replace("'", "''", $backupDbPath);
        $pdo->exec("VACUUM INTO '{$escapedPath}'");
        @chmod($backupDbPath, 0600);
        $sqliteSha = hash_file('sha256', $backupDbPath) ?: '';

        $assetChecksums = [];
        foreach (['sessions', 'prints/receipts', 'templates', 'luts'] as $relSub) {
            $srcSub = $this->config->dataDir . '/' . $relSub;
            if (!is_dir($srcSub)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($srcSub, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $absFile = $fileInfo->getPathname();
                $relFile = ltrim(str_replace('\\', '/', substr($absFile, strlen($this->config->dataDir))), '/');
                if (str_contains($relFile, '/pending/') || str_contains($relFile, '/.import-')) {
                    continue;
                }
                $dstFile = $targetDir . '/data/' . $relFile;
                $dstParent = dirname($dstFile);
                if (!is_dir($dstParent)) {
                    @mkdir($dstParent, 0700, true);
                }
                if (!@copy($absFile, $dstFile)) {
                    throw new ApiException('BACKUP_ERROR', "Không thể sao chép tệp tài sản '{$relFile}'.", 500);
                }
                $assetChecksums[$relFile] = hash_file('sha256', $dstFile) ?: '';
            }
        }

        $manifest = [
            'schema_version' => '1.0.0',
            'created_at' => Clock::nowIso(),
            'app_version' => $this->config->appVersion,
            'upstream_sha' => $this->config->upstreamSha,
            'sqlite_file' => 'photobooth.sqlite',
            'sqlite_sha256' => $sqliteSha,
            'assets' => $assetChecksums,
            'security_notice' => 'CẢNH BÁO BẢO MẬT: Bản sao lưu này chứa ảnh cá nhân của khách và metadata phiên. Chỉ lưu trên ổ đĩa được mã hóa/phân quyền chặt (0700) và xóa theo chính sách lưu trữ.',
        ];

        $manifestPath = $targetDir . '/backup-manifest.json';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        @chmod($manifestPath, 0600);

        return [
            'backup_dir' => $targetDir,
            'sqlite_sha256' => $sqliteSha,
            'asset_count' => count($assetChecksums),
        ];
    }

    /**
     * Restore a backup into the configured DATA_DIR & DATABASE_PATH after verifying all SHA-256 hashes.
     * Prevents old print requests from auto-executing when the worker starts (IT-14 & Section 13.3).
     *
     * @return array{restored_assets: int, reconciled_prints: int, revoked_tokens: int}
     */
    public function restoreFromBackup(string $sourceBackupDir, bool $signingKeyRestored = true): array
    {
        $sourceDir = rtrim($sourceBackupDir, '/');
        $manifestPath = $sourceDir . '/backup-manifest.json';
        if (!is_file($manifestPath)) {
            throw new ApiException('RESTORE_ERROR', 'Không tìm thấy backup-manifest.json trong thư mục sao lưu.', 422);
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || !isset($manifest['sqlite_sha256'], $manifest['assets']) || !is_array($manifest['assets'])) {
            throw new ApiException('RESTORE_ERROR', 'Nội dung backup-manifest.json không hợp lệ.', 422);
        }

        $backupDbPath = $sourceDir . '/photobooth.sqlite';
        if (!is_file($backupDbPath)) {
            throw new ApiException('RESTORE_ERROR', 'Không tìm thấy tệp photobooth.sqlite trong bản sao lưu.', 422);
        }

        $actualDbSha = hash_file('sha256', $backupDbPath) ?: '';
        if (!hash_equals((string) $manifest['sqlite_sha256'], $actualDbSha)) {
            throw new ApiException('INTEGRITY_ERROR', 'Mã băm SHA-256 của tệp SQLite trong bản sao lưu không khớp.', 409);
        }

        // Verify all asset hashes BEFORE modifying target volume
        foreach ($manifest['assets'] as $relFile => $expectedSha) {
            $srcAsset = $sourceDir . '/data/' . $relFile;
            if (!is_file($srcAsset)) {
                throw new ApiException('INTEGRITY_ERROR', "Thiếu tệp tài sản '{$relFile}' trong bản sao lưu.", 409);
            }
            $actualSha = hash_file('sha256', $srcAsset) ?: '';
            if (!hash_equals((string) $expectedSha, $actualSha)) {
                throw new ApiException('INTEGRITY_ERROR', "Mã băm SHA-256 của tài sản '{$relFile}' trong bản sao lưu bị hỏng.", 409);
            }
        }

        // Copy assets to DATA_DIR
        $restoredAssets = 0;
        foreach ($manifest['assets'] as $relFile => $expectedSha) {
            $srcAsset = $sourceDir . '/data/' . $relFile;
            $dstAsset = $this->config->dataDir . '/' . $relFile;
            $dstParent = dirname($dstAsset);
            if (!is_dir($dstParent)) {
                @mkdir($dstParent, 0750, true);
            }
            if (!@copy($srcAsset, $dstAsset)) {
                throw new ApiException('RESTORE_ERROR', "Không thể khôi phục tệp '{$relFile}'.", 500);
            }
            $restoredAssets++;
        }

        // Replace SQLite database file cleanly
        $targetDb = $this->config->databasePath;
        if (!is_dir(dirname($targetDb))) {
            @mkdir(dirname($targetDb), 0750, true);
        }
        // SQLite's backup API also replaces an existing WAL database safely on Windows,
        // where an open PDO connection prevents unlinking the database/WAL files.
        if (!class_exists(\SQLite3::class)) {
            throw new ApiException('RESTORE_ERROR', 'Khôi phục cần PHP extension sqlite3.', 503);
        }
        $sourceSqlite = new \SQLite3($backupDbPath, SQLITE3_OPEN_READONLY);
        $targetSqlite = new \SQLite3($targetDb, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $targetSqlite->busyTimeout($this->config->sqliteBusyTimeoutMs);
        try {
            if (!$sourceSqlite->backup($targetSqlite)) {
                throw new ApiException('RESTORE_ERROR', 'Không thể khôi phục SQLite. Dừng worker và đóng các giao dịch trước khi thử lại.', 409);
            }
        } finally {
            $targetSqlite->close();
            $sourceSqlite->close();
        }

        // Open fresh connection to restored DB to reconcile print requests and tokens before worker starts
        $restoredDb = new Database($targetDb, $this->config->sqliteBusyTimeoutMs);
        $nowIso = Clock::nowIso();

        return $restoredDb->transaction(static function (PDO $txPdo) use ($restoredAssets, $signingKeyRestored, $nowIso): array {
            // Section 9.4 & 13.3 & IT-14: Never allow worker to automatically re-submit prints from a backup!
            $prStmt = $txPdo->prepare(
                "UPDATE print_requests
                 SET status = 'UNKNOWN',
                     error_code = 'RESTORED_FROM_BACKUP',
                     error_message = 'Khôi phục từ bản sao lưu; đã chặn tự động gửi in lại để tránh in trùng.',
                     updated_at = :now
                 WHERE status IN ('PENDING', 'SUBMITTING')"
            );
            $prStmt->execute([':now' => $nowIso]);
            $reconciledPrints = $prStmt->rowCount();

            $revokedTokens = 0;
            if (!$signingKeyRestored) {
                $tkStmt = $txPdo->prepare('UPDATE share_tokens SET revoked_at = COALESCE(revoked_at, :now) WHERE revoked_at IS NULL');
                $tkStmt->execute([':now' => $nowIso]);
                $revokedTokens = $tkStmt->rowCount();
            }

            AuditLogger::record($txPdo, 'system.backup_restored', 'SUCCEEDED', null, null, null, [
                'restored_assets' => $restoredAssets,
                'reconciled_prints' => $reconciledPrints,
                'revoked_tokens' => $revokedTokens,
            ]);

            return [
                'restored_assets' => $restoredAssets,
                'reconciled_prints' => $reconciledPrints,
                'revoked_tokens' => $revokedTokens,
            ];
        });
    }
}

