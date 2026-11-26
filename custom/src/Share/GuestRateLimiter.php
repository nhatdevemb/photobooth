<?php

declare(strict_types=1);

namespace Photobooth\Operator\Share;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;

/**
 * Per-IP sliding-window rate limiter for the Guest LAN listener (port 8081).
 *
 * Uses one file per hashed client IP under DATA_DIR/rate-limits/guest/ with flock()
 * so unauthenticated LAN traffic cannot contend on photobooth.sqlite's writer lock
 * and distinct client IPs do not serialize against each other.
 */
final class GuestRateLimiter
{
    private AppConfig $config;
    private string $bucketDir;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
        $this->bucketDir = rtrim($config->dataDir, '/') . '/rate-limits/guest';
    }

    /**
     * Check per-IP request and invalid-token budgets, recording one request hit when allowed.
     */
    public function checkAndRecordHit(string $ipAddress, ?int $nowUnix = null): void
    {
        $maxRequests = $this->config->guestRateLimitMaxRequests;
        $maxInvalid = $this->config->guestRateLimitMaxInvalid;
        if ($maxRequests <= 0 && $maxInvalid <= 0) {
            return;
        }

        $now = $nowUnix ?? Clock::nowUnix();
        $window = max(1, $this->config->guestRateLimitWindowSeconds);
        $cutoff = $now - $window;

        $this->withLockedBucket($ipAddress, static function (array $state) use (
            $now,
            $window,
            $cutoff,
            $maxRequests,
            $maxInvalid
        ): array {
            $hits = array_values(array_filter(
                is_array($state['hits'] ?? null) ? $state['hits'] : [],
                static fn (mixed $ts): bool => is_int($ts) && $ts > $cutoff
            ));
            $invalid = array_values(array_filter(
                is_array($state['invalid'] ?? null) ? $state['invalid'] : [],
                static fn (mixed $ts): bool => is_int($ts) && $ts > $cutoff
            ));

            if ($maxInvalid > 0 && count($invalid) >= $maxInvalid) {
                $oldest = $invalid[0] ?? $now;
                $retryAfter = max(1, ($oldest + $window) - $now);
                throw new ApiException(
                    'GUEST_RATE_LIMITED',
                    'Quá nhiều yêu cầu liên kết không hợp lệ. Vui lòng thử lại sau ít giây.',
                    429,
                    ['retry_after_seconds' => $retryAfter],
                    ['Retry-After' => (string) $retryAfter]
                );
            }

            if ($maxRequests > 0 && count($hits) >= $maxRequests) {
                $oldest = $hits[0] ?? $now;
                $retryAfter = max(1, ($oldest + $window) - $now);
                throw new ApiException(
                    'GUEST_RATE_LIMITED',
                    'Bạn đã gửi quá nhiều yêu cầu tải ảnh trong thời gian ngắn. Vui lòng thử lại sau ít giây.',
                    429,
                    ['retry_after_seconds' => $retryAfter],
                    ['Retry-After' => (string) $retryAfter]
                );
            }

            $hits[] = $now;
            return ['hits' => $hits, 'invalid' => $invalid];
        });
    }

    /**
     * Record a 404 invalid/expired/revoked share lookup against this IP's invalid-token budget.
     */
    public function recordInvalidAttempt(string $ipAddress, ?int $nowUnix = null): void
    {
        if ($this->config->guestRateLimitMaxInvalid <= 0) {
            return;
        }

        $now = $nowUnix ?? Clock::nowUnix();
        $window = max(1, $this->config->guestRateLimitWindowSeconds);
        $cutoff = $now - $window;

        $this->withLockedBucket($ipAddress, static function (array $state) use ($now, $cutoff): array {
            $hits = array_values(array_filter(
                is_array($state['hits'] ?? null) ? $state['hits'] : [],
                static fn (mixed $ts): bool => is_int($ts) && $ts > $cutoff
            ));
            $invalid = array_values(array_filter(
                is_array($state['invalid'] ?? null) ? $state['invalid'] : [],
                static fn (mixed $ts): bool => is_int($ts) && $ts > $cutoff
            ));
            $invalid[] = $now;
            return ['hits' => $hits, 'invalid' => $invalid];
        });
    }

    /**
     * Purge expired per-IP rate-limit bucket files during periodic maintenance.
     */
    public function purgeExpiredBuckets(?int $nowUnix = null): int
    {
        if (!is_dir($this->bucketDir)) {
            return 0;
        }
        $now = $nowUnix ?? Clock::nowUnix();
        $staleBefore = $now - (max(1, $this->config->guestRateLimitWindowSeconds) * 2);
        $purged = 0;

        foreach (glob($this->bucketDir . '/*.json') ?: [] as $file) {
            if (!is_file($file) || is_link($file)) {
                continue;
            }
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime >= $staleBefore) {
                continue;
            }
            $fp = @fopen($file, 'rb');
            if ($fp === false) {
                continue;
            }
            $canUnlink = false;
            try {
                if (@flock($fp, LOCK_EX | LOCK_NB)) {
                    $stat = @fstat($fp);
                    if (!is_array($stat) || ($stat['mtime'] ?? 0) < $staleBefore) {
                        $canUnlink = true;
                    }
                    @flock($fp, LOCK_UN);
                }
            } finally {
                fclose($fp);
            }
            // Close file handle BEFORE unlinking so Windows NTFS never enters STATUS_DELETE_PENDING
            if ($canUnlink && @unlink($file)) {
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function withLockedBucket(string $ipAddress, callable $mutator): void
    {
        if (!is_dir($this->bucketDir) && !@mkdir($this->bucketDir, 0750, true) && !is_dir($this->bucketDir)) {
            return;
        }
        $normalizedIp = strtolower(trim($ipAddress));
        if ($normalizedIp === '') {
            $normalizedIp = '0.0.0.0';
        }
        $path = $this->bucketDir . '/' . hash('sha256', $normalizedIp) . '.json';
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return;
        }

        try {
            if (!@flock($fp, LOCK_EX)) {
                return;
            }
            $raw = (string) stream_get_contents($fp);
            $current = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($current)) {
                $current = ['hits' => [], 'invalid' => []];
            }

            $updated = $mutator($current);
            $encoded = json_encode($updated, JSON_UNESCAPED_SLASHES) ?: '{"hits":[],"invalid":[]}';
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $encoded);
            fflush($fp);
            @flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }
}
