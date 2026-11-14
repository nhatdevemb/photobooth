<?php

declare(strict_types=1);

namespace Photobooth\Operator\Storage;

use PDO;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;

final class AuditLogger
{
    /**
     * Record an audit event inside the given PDO connection/transaction.
     *
     * @param array<string, mixed> $safeMetadata
     */
    public static function record(
        PDO $pdo,
        string $action,
        string $outcome,
        ?string $actorId = null,
        ?string $sessionId = null,
        ?string $jobId = null,
        array $safeMetadata = []
    ): string {
        $id = Uuid::v4();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_events (id, actor_id, session_id, job_id, action, outcome, safe_metadata, created_at)
             VALUES (:id, :actor_id, :session_id, :job_id, :action, :outcome, :safe_metadata, :created_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':actor_id' => $actorId,
            ':session_id' => $sessionId,
            ':job_id' => $jobId,
            ':action' => $action,
            ':outcome' => $outcome,
            ':safe_metadata' => json_encode($safeMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ':created_at' => Clock::nowIso(),
        ]);
        return $id;
    }
}

