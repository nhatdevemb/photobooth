<?php

declare(strict_types=1);

namespace Photobooth\Operator\Session;

use PDO;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;

final class IdempotencyService
{
    public static function validateKey(?string $key): string
    {
        if ($key === null || trim($key) === '') {
            throw new ApiException(
                'MISSING_IDEMPOTENCY_KEY',
                'Thiếu header Idempotency-Key bắt buộc cho thao tác thay đổi dữ liệu.',
                400
            );
        }
        $trimmed = trim($key);
        if (strlen($trimmed) < 8 || strlen($trimmed) > 128 || preg_match('/^[a-zA-Z0-9_.:-]+$/', $trimmed) !== 1) {
            throw new ApiException(
                'INVALID_IDEMPOTENCY_KEY',
                'Giá trị Idempotency-Key không hợp lệ.',
                400
            );
        }
        return $trimmed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function hashPayload(array $payload): string
    {
        self::ksortRecursive($payload);
        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        return hash('sha256', $canonical);
    }

    /**
     * Check if an idempotency record already exists for (actor_id, operation, key).
     * Throws 409 IDEMPOTENCY_CONFLICT if payload_hash differs.
     *
     * @return ?array{status_code: int, resource_id: string, response: array<string, mixed>}
     */
    public static function checkReplay(
        PDO $pdo,
        string $actorId,
        string $operation,
        string $idempotencyKey,
        string $payloadHash
    ): ?array {
        $stmt = $pdo->prepare(
            'SELECT payload_hash, resource_id, status_code, response_json
             FROM idempotency_records
             WHERE actor_id = :actor AND operation = :op AND idempotency_key = :k'
        );
        $stmt->execute([
            ':actor' => $actorId,
            ':op' => $operation,
            ':k' => $idempotencyKey,
        ]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        if (!hash_equals((string) $row['payload_hash'], $payloadHash)) {
            throw new ApiException(
                'IDEMPOTENCY_CONFLICT',
                'Idempotency-Key đã được sử dụng cho một nội dung yêu cầu khác.',
                409
            );
        }

        $decoded = json_decode((string) $row['response_json'], true);
        return [
            'status_code' => (int) $row['status_code'],
            'resource_id' => (string) $row['resource_id'],
            'response' => is_array($decoded) ? $decoded : [],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function record(
        PDO $pdo,
        string $actorId,
        string $operation,
        string $idempotencyKey,
        ?string $sessionId,
        string $payloadHash,
        string $resourceId,
        int $statusCode,
        array $response
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO idempotency_records
             (actor_id, operation, idempotency_key, session_id, payload_hash, resource_id, status_code, response_json, created_at)
             VALUES (:actor, :op, :k, :sid, :ph, :rid, :sc, :resp, :now)'
        );
        $stmt->execute([
            ':actor' => $actorId,
            ':op' => $operation,
            ':k' => $idempotencyKey,
            ':sid' => $sessionId,
            ':ph' => $payloadHash,
            ':rid' => $resourceId,
            ':sc' => $statusCode,
            ':resp' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ':now' => Clock::nowIso(),
        ]);
    }

    /**
     * @param array<string, mixed> $array
     */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}

