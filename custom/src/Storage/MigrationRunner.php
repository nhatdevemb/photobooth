<?php

declare(strict_types=1);

namespace Photobooth\Operator\Storage;

use Photobooth\Operator\Support\Clock;

final class MigrationRunner
{
    private Database $db;
    private string $migrationsDir;

    public function __construct(Database $db, string $migrationsDir)
    {
        $this->db = $db;
        $this->migrationsDir = rtrim($migrationsDir, '/');
    }

    /**
     * Ensure schema_migrations exists and apply any pending numbered SQL migrations.
     * Backs up the database before applying new migrations if tables already exist.
     *
     * @return list<string> Applied migration filenames
     */
    public function migrate(): array
    {
        $pdo = $this->db->pdo();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                checksum TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )'
        );

        $appliedStmt = $pdo->query('SELECT version, checksum FROM schema_migrations ORDER BY version ASC');
        $applied = [];
        if ($appliedStmt !== false) {
            foreach ($appliedStmt->fetchAll() as $row) {
                $applied[(string) $row['version']] = (string) $row['checksum'];
            }
        }

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $pending = [];
        foreach ($files as $file) {
            $version = basename($file);
            $checksum = hash_file('sha256', $file) ?: '';
            if (!isset($applied[$version])) {
                $pending[] = ['version' => $version, 'path' => $file, 'checksum' => $checksum];
            }
        }

        if ($pending === []) {
            return [];
        }

        // If database already had prior migrations, create a consistent pre-migration backup
        if ($applied !== [] && is_file($this->db->path())) {
            $backupPath = $this->db->path() . '.pre-migrate-' . Clock::nowUnix() . '.bak';
            $escaped = str_replace("'", "''", $backupPath);
            $pdo->exec("VACUUM INTO '{$escaped}'");
        }

        $newlyApplied = [];
        foreach ($pending as $item) {
            $sql = (string) file_get_contents($item['path']);
            $this->db->transaction(static function ($txPdo) use ($sql, $item): void {
                $txPdo->exec($sql);
                $stmt = $txPdo->prepare(
                    'INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (:version, :checksum, :applied_at)'
                );
                $stmt->execute([
                    ':version' => $item['version'],
                    ':checksum' => $item['checksum'],
                    ':applied_at' => Clock::nowIso(),
                ]);
            });
            $newlyApplied[] = $item['version'];
        }

        return $newlyApplied;
    }

    /**
     * @return array{ok: bool, applied_count: int, pending_count: int, versions: list<string>}
     */
    public function status(): array
    {
        $pdo = $this->db->pdo();
        $tableCheck = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'");
        if ($tableCheck === false || $tableCheck->fetchColumn() === false) {
            $allFiles = glob($this->migrationsDir . '/*.sql') ?: [];
            return [
                'ok' => false,
                'applied_count' => 0,
                'pending_count' => count($allFiles),
                'versions' => [],
            ];
        }

        $rows = $pdo->query('SELECT version FROM schema_migrations ORDER BY version ASC')->fetchAll();
        $versions = array_map(static fn (array $r): string => (string) $r['version'], $rows);
        $allFiles = glob($this->migrationsDir . '/*.sql') ?: [];
        $pendingCount = 0;
        foreach ($allFiles as $file) {
            if (!in_array(basename($file), $versions, true)) {
                $pendingCount++;
            }
        }

        return [
            'ok' => $pendingCount === 0 && count($versions) > 0,
            'applied_count' => count($versions),
            'pending_count' => $pendingCount,
            'versions' => $versions,
        ];
    }
}

