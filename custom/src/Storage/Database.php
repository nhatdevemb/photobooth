<?php

declare(strict_types=1);

namespace Photobooth\Operator\Storage;

use PDO;
use PDOException;
use Photobooth\Operator\Support\ApiException;
use Throwable;

final class Database
{
    private PDO $pdo;
    private string $dbPath;
    private int $busyTimeoutMs;
    private bool $inTx = false;

    public function __construct(string $dbPath, int $busyTimeoutMs = 5000)
    {
        $this->dbPath = $dbPath;
        $this->busyTimeoutMs = $busyTimeoutMs;
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new ApiException(
                    'STORAGE_ERROR',
                    'Không thể khởi tạo thư mục lưu trữ cơ sở dữ liệu.',
                    503
                );
            }
        }

        try {
            $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->configurePragmas();
        } catch (PDOException $e) {
            throw new ApiException(
                'DATABASE_UNAVAILABLE',
                'Không thể kết nối cơ sở dữ liệu SQLite.',
                503,
                [],
                [],
                $e
            );
        }
    }

    private function configurePragmas(): void
    {
        $timeoutSec = max(1, (int) ceil($this->busyTimeoutMs / 1000));
        $this->pdo->setAttribute(PDO::ATTR_TIMEOUT, $timeoutSec);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = ' . $this->busyTimeoutMs);
        $this->pdo->exec('PRAGMA synchronous = FULL');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->dbPath;
    }

    /**
     * Run a callback inside an IMMEDIATE SQLite transaction.
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->inTx || $this->pdo->inTransaction()) {
            return $callback($this->pdo);
        }

        try {
            $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            $this->inTx = true;
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'locked') || str_contains(strtolower($e->getMessage()), 'busy')) {
                throw new ApiException(
                    'DATABASE_BUSY',
                    'Hệ thống đang bận xử lý dữ liệu, vui lòng thử lại sau giây lát.',
                    503,
                    [],
                    [],
                    $e
                );
            }
            throw $e;
        }

        try {
            $result = $callback($this->pdo);
            $this->pdo->exec('COMMIT');
            $this->inTx = false;
            return $result;
        } catch (Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            $this->inTx = false;
            if ($e instanceof PDOException && (str_contains(strtolower($e->getMessage()), 'locked') || str_contains(strtolower($e->getMessage()), 'busy'))) {
                throw new ApiException(
                    'DATABASE_BUSY',
                    'Cơ sở dữ liệu đang bận, thao tác đã được hoàn tác an toàn.',
                    503,
                    [],
                    [],
                    $e
                );
            }
            throw $e;
        }
    }
}
