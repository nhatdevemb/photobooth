<?php

declare(strict_types=1);

namespace Photobooth\Operator\Maintenance;

use Photobooth\Operator\Support\Clock;

final class StructuredLogger
{
    private const MAX_DAYS = 14;
    private const MAX_TOTAL_BYTES = 209_715_200; // 200 MiB
    private const SENSITIVE_KEYS = [
        'password',
        'password_hash',
        'token',
        'share_token',
        'raw_token',
        'csrf_token',
        'cookie',
        'secret',
        'signing_key',
        'share_signing_key',
        'authorization',
    ];

    private string $logDir;
    private ?string $requestId;

    public function __construct(string $dataDir, ?string $requestId = null)
    {
        $this->logDir = rtrim($dataDir, '/') . '/logs';
        $this->requestId = $requestId;
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }
    }

    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $event, string $code = 'OK', array $context = []): void
    {
        $this->write('INFO', $event, $code, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $event, string $code, array $context = []): void
    {
        $this->write('WARNING', $event, $code, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $event, string $code, array $context = []): void
    {
        $this->write('ERROR', $event, $code, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function write(string $severity, string $event, string $code, array $context = []): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0750, true);
        }

        $sanitized = $this->sanitize($context);
        $nowUnix = Clock::nowUnix();
        $datePart = gmdate('Y-m-d', $nowUnix);
        $logFile = $this->logDir . '/operator-' . $datePart . '.jsonl';

        $entry = [
            'timestamp' => Clock::fromUnixIso($nowUnix),
            'severity' => $severity,
            'event' => $event,
            'code' => $code,
            'request_id' => $sanitized['request_id'] ?? $this->requestId,
            'session_id' => $sanitized['session_id'] ?? null,
            'job_id' => $sanitized['job_id'] ?? null,
            'print_request_id' => $sanitized['print_request_id'] ?? null,
            'context' => $sanitized,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate daily logs: remove files older than 14 days and enforce 200 MiB total ceiling.
     *
     * @return array{deleted_files: int, remaining_bytes: int}
     */
    public function rotateLogs(?int $maxDays = null, ?int $maxTotalBytes = null): array
    {
        $days = $maxDays ?? self::MAX_DAYS;
        $capBytes = $maxTotalBytes ?? self::MAX_TOTAL_BYTES;

        if (!is_dir($this->logDir)) {
            return ['deleted_files' => 0, 'remaining_bytes' => 0];
        }

        $files = glob($this->logDir . '/operator-*.jsonl') ?: [];
        $cutoffUnix = Clock::nowUnix() - ($days * 86400);
        $deletedCount = 0;

        $remaining = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $mtime = (int) @filemtime($file);
            if (preg_match('/operator-(\d{4}-\d{2}-\d{2})\.jsonl$/', $file, $m) === 1) {
                $parsedTs = strtotime($m[1] . 'T23:59:59Z');
                if ($parsedTs !== false) {
                    $mtime = $parsedTs;
                }
            }

            if ($mtime < $cutoffUnix) {
                if (@unlink($file)) {
                    $deletedCount++;
                }
            } else {
                $remaining[] = [
                    'path' => $file,
                    'mtime' => $mtime,
                    'size' => (int) @filesize($file),
                ];
            }
        }

        // Sort oldest first so if total exceeds 200 MiB we remove oldest files first
        usort($remaining, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        $totalBytes = array_sum(array_column($remaining, 'size'));
        while ($totalBytes > $capBytes && count($remaining) > 1) {
            $oldest = array_shift($remaining);
            if ($oldest !== null && @unlink($oldest['path'])) {
                $totalBytes -= $oldest['size'];
                $deletedCount++;
            }
        }

        return [
            'deleted_files' => $deletedCount,
            'remaining_bytes' => max(0, $totalBytes),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if ($lowerKey === $sensitive || str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_string($value) && strlen($value) > 1024) {
                $clean[$key] = substr($value, 0, 1024) . '...[TRUNCATED]';
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
}

