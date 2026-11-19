<?php

declare(strict_types=1);

namespace Photobooth\Operator\Printer;

use PDO;
use Photobooth\Operator\Event\EventSettings;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Session\IdempotencyService;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;
use Throwable;

final class PrintService
{
    private Database $db;
    private AppConfig $config;
    private AssetStore $assetStore;
    private PrintAdapterInterface $adapter;
    private StructuredLogger $logger;

    public function __construct(
        Database $db,
        AppConfig $config,
        AssetStore $assetStore,
        PrintAdapterInterface $adapter,
        StructuredLogger $logger
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->assetStore = $assetStore;
        $this->adapter = $adapter;
        $this->logger = $logger;
    }

    public function adapter(): PrintAdapterInterface
    {
        return $this->adapter;
    }

    /**
     * Enqueue a manual print or reprint request with idempotency and revision checking.
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function createPrintRequest(
        string $sessionId,
        string $actorId,
        int $expectedRevision,
        string $finalAssetId,
        int $copies,
        string $action,
        string $idempotencyKey
    ): array {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload([
            'session_id' => $sessionId,
            'final_asset_id' => $finalAssetId,
            'copies' => $copies,
            'action' => $action,
        ]);

        // Replay check BEFORE revision check or validation
        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.print',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        if ($copies < 1 || $copies > 10) {
            throw new ApiException(
                'INVALID_COPIES',
                'Mỗi yêu cầu in phải có từ 1 đến 10 bản.',
                422
            );
        }

        if (!in_array($action, ['print', 'reprint'], true)) {
            throw new ApiException(
                'INVALID_ACTION',
                "Hành động in không hợp lệ: '{$action}' (chỉ hỗ trợ 'print' hoặc 'reprint').",
                422
            );
        }

        if (!Uuid::isValid($finalAssetId)) {
            throw new ApiException('INVALID_STATE', 'Mã ảnh cuối (final_asset_id) không hợp lệ.', 422);
        }

        $this->assetStore->assertDiskSpaceAvailable();

        return $this->db->transaction(function (PDO $pdo) use (
            $sessionId,
            $actorId,
            $expectedRevision,
            $finalAssetId,
            $copies,
            $action,
            $idemKey,
            $payloadHash
        ): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.print', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $sStmt = $pdo->prepare('SELECT * FROM sessions WHERE id = :id AND deleted_at IS NULL');
            $sStmt->execute([':id' => $sessionId]);
            $session = $sStmt->fetch();
            if (!is_array($session)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
            }

            $currentRev = (int) $session['revision'];
            if ($currentRev !== $expectedRevision) {
                throw new ApiException(
                    'REVISION_CONFLICT',
                    'Phiên chụp đã thay đổi (revision conflict). Vui lòng tải lại dữ liệu phiên.',
                    409,
                    ['current_revision' => $currentRev]
                );
            }

            $state = (string) $session['state'];
            if ($action === 'print') {
                if ($state !== 'FINALIZED') {
                    throw new ApiException(
                        'INVALID_STATE',
                        'Chỉ có thể gửi lệnh in đầu tiên khi phiên đã được duyệt khóa ảnh cuối (FINALIZED).',
                        422
                    );
                }
                if ((string) ($session['current_final_id'] ?? '') !== $finalAssetId) {
                    throw new ApiException(
                        'INVALID_STATE',
                        'Với action=print, ảnh cuối phải là bản final hiện hành của phiên.',
                        422
                    );
                }

                // Check if an initial print was already accepted for this final_asset_id
                $dupCheck = $pdo->prepare(
                    "SELECT COUNT(*) FROM print_requests
                     WHERE final_asset_id = :fid AND action = 'print'
                       AND (status != 'FAILED' OR resolution_outcome = 'observed_completed')"
                );
                $dupCheck->execute([':fid' => $finalAssetId]);
                if ((int) $dupCheck->fetchColumn() > 0) {
                    throw new ApiException(
                        'INVALID_STATE',
                        'Ảnh cuối này đã có lệnh in đầu tiên. Vui lòng chọn chức năng In lại (action=reprint).',
                        422
                    );
                }
            } else {
                // action === 'reprint'
                if (!in_array($state, ['FINALIZED', 'CLOSED'], true)) {
                    throw new ApiException(
                        'INVALID_STATE',
                        'Chỉ có thể in lại từ phiên đã khóa ảnh cuối (FINALIZED) hoặc đã đóng (CLOSED).',
                        422
                    );
                }
            }

            $faStmt = $pdo->prepare('SELECT * FROM final_assets WHERE id = :fid AND session_id = :sid');
            $faStmt->execute([':fid' => $finalAssetId, ':sid' => $sessionId]);
            $finalAsset = $faStmt->fetch();
            if (!is_array($finalAsset)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy tài sản ảnh cuối thuộc phiên này.', 404);
            }

            // Verify SHA-256 on disk right away
            $this->assetStore->verifyAssetIntegrity(
                (string) $finalAsset['path'],
                (string) $finalAsset['sha256'],
                $pdo,
                $sessionId
            );

            // Check if session has any unresolved UNKNOWN print requests to include a warning flag
            $unkStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM print_requests WHERE session_id = :sid AND status = 'UNKNOWN'"
            );
            $unkStmt->execute([':sid' => $sessionId]);
            $unknownCount = (int) $unkStmt->fetchColumn();

            $mode = $this->adapter->getMode();
            $quota = EventSettings::quota($pdo, $session['event_id'], $mode);
            if ($quota['remaining'] !== null && $copies > $quota['remaining']) {
                throw new ApiException('PRINT_QUOTA_EXCEEDED', 'Còn có thể in ' . $quota['remaining'] . ' bản.', 409, $quota);
            }

            $newRevision = $currentRev + 1;
            $nowIso = Clock::nowIso();
            $printRequestId = Uuid::v4();

            $pdo->prepare('UPDATE sessions SET revision = :rev, updated_at = :now WHERE id = :id')
                ->execute([':rev' => $newRevision, ':now' => $nowIso, ':id' => $sessionId]);

            $pdo->prepare(
                'INSERT INTO print_requests
                 (id, session_id, final_asset_id, copies, action, status, created_by, created_at, updated_at, next_poll_at, adapter_mode)
                 VALUES (:id, :sid, :fid, :copies, :action, :status, :uid, :now, :now, :now, :mode)'
            )->execute([
                ':id' => $printRequestId,
                ':sid' => $sessionId,
                ':fid' => $finalAssetId,
                ':copies' => $copies,
                ':mode' => $mode,
                ':action' => $action,
                ':status' => 'PENDING',
                ':uid' => $actorId,
                ':now' => $nowIso,
            ]);

            $response = [
                'print_request_id' => $printRequestId,
                'session_id' => $sessionId,
                'final_asset_id' => $finalAssetId,
                'copies' => $copies,
                'action' => $action,
                'status' => 'PENDING',
                'revision' => $newRevision,
                'mode' => $this->adapter->getMode(),
                'has_unresolved_unknown' => $unknownCount > 0,
                'created_at' => $nowIso,
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.print',
                $idemKey,
                $sessionId,
                $payloadHash,
                $printRequestId,
                202,
                $response
            );

            AuditLogger::record($pdo, 'print.' . $action . '_requested', 'PENDING', $actorId, $sessionId, null, [
                'print_request_id' => $printRequestId,
                'final_asset_id' => $finalAssetId,
                'copies' => $copies,
                'revision' => $newRevision,
            ]);

            $this->logger->info('print_request_enqueued', 'OK', [
                'session_id' => $sessionId,
                'print_request_id' => $printRequestId,
                'action' => $action,
            ]);

            return ['status_code' => 202, 'body' => $response];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrintRequest(string $printRequestId): array
    {
        if (!Uuid::isValid($printRequestId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy yêu cầu in.', 404);
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM print_requests WHERE id = :id');
        $stmt->execute([':id' => $printRequestId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy yêu cầu in.', 404);
        }

        return $this->formatPrintRow($row);
    }

    /**
     * Admin resolves an UNKNOWN print request with observed_completed or confirmed_not_printed.
     * Does not automatically submit a new print job.
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function resolveUnknownPrint(
        string $printRequestId,
        string $adminUserId,
        int $expectedRevision,
        string $outcome,
        string $note,
        string $idempotencyKey,
        ?int $completedCopies = null
    ): array {
        if (!Uuid::isValid($printRequestId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy yêu cầu in.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $noteTrimmed = trim($note);
        $payloadHash = IdempotencyService::hashPayload([
            'print_request_id' => $printRequestId,
            'outcome' => $outcome,
            'note' => $noteTrimmed,
            ...($completedCopies === null ? [] : ['completed_copies' => $completedCopies]),
        ]);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $adminUserId,
            'print.resolve',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        if (!in_array($outcome, ['observed_completed', 'confirmed_not_printed'], true)) {
            throw new ApiException(
                'INVALID_INPUT',
                "Kết quả đối chiếu không hợp lệ (chỉ nhận 'observed_completed' hoặc 'confirmed_not_printed').",
                422
            );
        }

        if ($noteTrimmed === '') {
            throw new ApiException('INVALID_INPUT', 'Ghi chú đối chiếu (note) không được để trống.', 422);
        }

        return $this->db->transaction(function (PDO $pdo) use (
            $printRequestId,
            $adminUserId,
            $expectedRevision,
            $outcome,
            $noteTrimmed,
            $completedCopies,
            $idemKey,
            $payloadHash
        ): array {
            $prStmt = $pdo->prepare('SELECT * FROM print_requests WHERE id = :id');
            $prStmt->execute([':id' => $printRequestId]);
            $pr = $prStmt->fetch();
            if (!is_array($pr)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy yêu cầu in.', 404);
            }

            $sessionId = (string) $pr['session_id'];
            $sStmt = $pdo->prepare('SELECT * FROM sessions WHERE id = :id AND deleted_at IS NULL');
            $sStmt->execute([':id' => $sessionId]);
            $session = $sStmt->fetch();
            if (!is_array($session)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên của yêu cầu in.', 404);
            }

            $currentRev = (int) $session['revision'];
            if ($currentRev !== $expectedRevision) {
                throw new ApiException(
                    'REVISION_CONFLICT',
                    'Phiên đã thay đổi revision. Vui lòng tải lại trước khi đối chiếu.',
                    409,
                    ['current_revision' => $currentRev]
                );
            }

            if ((string) $pr['status'] !== 'UNKNOWN') {
                throw new ApiException(
                    'INVALID_STATE',
                    'Chỉ có thể đối chiếu yêu cầu in đang ở trạng thái UNKNOWN.',
                    422
                );
            }

            $observed = $outcome === 'observed_completed';
            $actual = $completedCopies ?? ($observed ? (int) $pr['copies'] : 0);
            if (($observed && ($actual < 1 || $actual > (int) $pr['copies'])) || (!$observed && $actual !== 0)) {
                throw new ApiException('INVALID_COPIES', 'Số bản thực tế phải khớp kết quả đối chiếu.', 422);
            }
            $newStatus = $observed ? 'COMPLETED' : 'FAILED';
            $newRevision = $currentRev + 1;
            $nowIso = Clock::nowIso();

            $pdo->prepare('UPDATE sessions SET revision = :rev, updated_at = :now WHERE id = :id')
                ->execute([':rev' => $newRevision, ':now' => $nowIso, ':id' => $sessionId]);

            $pdo->prepare(
                'UPDATE print_requests
                 SET status = :st,
                     completed_copies = :actual,
                     resolved_by = :uid,
                     resolution_outcome = :out,
                     resolution_note = :note,
                     resolved_at = :now,
                     updated_at = :now,
                     completed_at = :now
                 WHERE id = :id'
            )->execute([
                ':st' => $newStatus,
                ':actual' => $actual,
                ':uid' => $adminUserId,
                ':out' => $outcome,
                ':note' => $noteTrimmed,
                ':now' => $nowIso,
                ':id' => $printRequestId,
            ]);

            $updatedRowStmt = $pdo->prepare('SELECT * FROM print_requests WHERE id = :id');
            $updatedRowStmt->execute([':id' => $printRequestId]);
            $updatedRow = (array) $updatedRowStmt->fetch();

            $response = $this->formatPrintRow($updatedRow);
            $response['session_revision'] = $newRevision;

            IdempotencyService::record(
                $pdo,
                $adminUserId,
                'print.resolve',
                $idemKey,
                $sessionId,
                $payloadHash,
                $printRequestId,
                200,
                $response
            );

            AuditLogger::record($pdo, 'print.unknown_resolved', $outcome, $adminUserId, $sessionId, null, [
                'print_request_id' => $printRequestId,
                'previous_status' => 'UNKNOWN',
                'new_status' => $newStatus,
                'resolution_outcome' => $outcome,
                'note' => $noteTrimmed,
            ]);

            return ['status_code' => 200, 'body' => $response];
        });
    }

    /**
     * Worker: claim and submit one PENDING print request, then return to tick loop.
     * Follows Section 8.2 strictly:
     * 1. Verify SHA-256 before submit; if mismatch -> FAILED without calling adapter.
     * 2. Persist SUBMITTING before executing side-effecting adapter call.
     * 3. Submit once; on adapter_job_id -> QUEUED.
     * 4. On ambiguous error after SUBMITTING -> UNKNOWN (never auto-retry).
     */
    public function processNextPendingPrint(?string $scenarioOverride = null, bool $crashAfterSubmitting = false): ?array
    {
        $pdo = $this->db->pdo();
        if ($pdo->query("SELECT 1 FROM print_requests WHERE status = 'PENDING' LIMIT 1")->fetchColumn() === false) {
            return null;
        }

        // Claim one PENDING print_request and transition to SUBMITTING after verifying SHA-256
        $claimed = $this->db->transaction(function (PDO $txPdo): ?array {
            $stmt = $txPdo->query(
                "SELECT pr.*, fa.path AS final_path, fa.sha256 AS final_sha256
                 FROM print_requests pr
                 JOIN final_assets fa ON fa.id = pr.final_asset_id
                 WHERE pr.status = 'PENDING'
                 ORDER BY pr.created_at ASC
                 LIMIT 1"
            );
            $row = $stmt !== false ? $stmt->fetch() : false;
            if (!is_array($row)) {
                return null;
            }

            $prId = (string) $row['id'];
            $sessionId = (string) $row['session_id'];
            $nowIso = Clock::nowIso();

            // Step 5: Verify SHA-256 right before submit
            try {
                $absPath = $this->assetStore->verifyAssetIntegrity(
                    (string) $row['final_path'],
                    (string) $row['final_sha256'],
                    $txPdo,
                    $sessionId
                );
            } catch (ApiException $e) {
                $txPdo->prepare(
                    "UPDATE print_requests
                     SET status = 'FAILED', error_code = :code, error_message = :msg, updated_at = :now, completed_at = :now
                     WHERE id = :id"
                )->execute([
                    ':code' => $e->getErrorCode(),
                    ':msg' => $e->getMessage(),
                    ':now' => $nowIso,
                    ':id' => $prId,
                ]);
                AuditLogger::record($txPdo, 'print.submit', 'FAILED_HASH_MISMATCH', null, $sessionId, null, [
                    'print_request_id' => $prId,
                    'error_code' => $e->getErrorCode(),
                ]);
                return ['failed_pre_submit' => true, 'id' => $prId];
            }

            // Step 6: Persist SUBMITTING durably before calling adapter
            $txPdo->prepare(
                "UPDATE print_requests SET status = 'SUBMITTING', updated_at = :now WHERE id = :id AND status = 'PENDING'"
            )->execute([
                ':now' => $nowIso,
                ':id' => $prId,
            ]);

            $row['final_abs_path'] = $absPath;
            return $row;
        });

        if ($claimed === null) {
            return null;
        }

        if (!empty($claimed['failed_pre_submit'])) {
            return $this->getPrintRequest((string) $claimed['id']);
        }

        $prId = (string) $claimed['id'];
        $sessionId = (string) $claimed['session_id'];

        // Fault injection hook for testing worker crash while in SUBMITTING state (IT-09)
        if ($crashAfterSubmitting) {
            return $this->getPrintRequest($prId);
        }

        // Step 7: Submit once to adapter (outside SQLite transaction!)
        try {
            $submitResult = $this->adapter->submit(
                $prId,
                $sessionId,
                (string) $claimed['final_asset_id'],
                (string) $claimed['final_abs_path'],
                (string) $claimed['final_sha256'],
                (int) $claimed['copies'],
                $scenarioOverride
            );

            $nowIso = Clock::nowIso();
            $this->db->transaction(static function (PDO $txPdo) use ($prId, $sessionId, $submitResult, $nowIso): void {
                $txPdo->prepare(
                    "UPDATE print_requests
                     SET status = :st, adapter_job_id = :jid, submitted_at = :sub, updated_at = :now, next_poll_at = :now
                     WHERE id = :id"
                )->execute([
                    ':st' => $submitResult['status'],
                    ':jid' => $submitResult['adapter_job_id'],
                    ':sub' => $submitResult['submitted_at'],
                    ':now' => $nowIso,
                    ':id' => $prId,
                ]);
                AuditLogger::record($txPdo, 'print.submitted', $submitResult['status'], null, $sessionId, null, [
                    'print_request_id' => $prId,
                    'adapter_job_id' => $submitResult['adapter_job_id'],
                ]);
            });
        } catch (Throwable $e) {
            $errorCode = $e instanceof ApiException ? $e->getErrorCode() : 'PRINT_OUTCOME_UNKNOWN';
            $errorMsg = $e->getMessage();
            $submittedToSpool = true;
            if ($e instanceof ApiException) {
                $details = $e->getDetails();
                if (array_key_exists('submitted_to_spool', $details) && $details['submitted_to_spool'] === false) {
                    $submittedToSpool = false;
                }
            }

            $finalStatus = $submittedToSpool ? 'UNKNOWN' : 'FAILED';
            $nowIso = Clock::nowIso();

            $this->db->transaction(static function (PDO $txPdo) use (
                $prId,
                $sessionId,
                $finalStatus,
                $errorCode,
                $errorMsg,
                $nowIso
            ): void {
                $txPdo->prepare(
                    'UPDATE print_requests
                     SET status = :st, error_code = :code, error_message = :msg, updated_at = :now, completed_at = :now
                     WHERE id = :id'
                )->execute([
                    ':st' => $finalStatus,
                    ':code' => $errorCode,
                    ':msg' => $errorMsg,
                    ':now' => $nowIso,
                    ':id' => $prId,
                ]);
                AuditLogger::record($txPdo, 'print.submit_error', $finalStatus, null, $sessionId, null, [
                    'print_request_id' => $prId,
                    'error_code' => $errorCode,
                ]);
            });

            $this->logger->error('print_submit_failed_or_unknown', $errorCode, [
                'session_id' => $sessionId,
                'print_request_id' => $prId,
                'status' => $finalStatus,
                'error' => $errorMsg,
            ]);
        }

        return $this->getPrintRequest($prId);
    }

    /**
     * Poll QUEUED / PRINTING requests that are due for status check.
     * Does not modify session revision (Section 4.3 rule 6).
     */
    public function pollActivePrints(?string $scenarioOverride = null): int
    {
        $nowUnix = Clock::nowUnix();
        $nowIso = Clock::fromUnixIso($nowUnix);
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            "SELECT * FROM print_requests
             WHERE status IN ('QUEUED', 'PRINTING')
               AND (next_poll_at IS NULL OR next_poll_at <= :now)
             ORDER BY updated_at ASC
             LIMIT 10"
        );
        $stmt->execute([':now' => $nowIso]);
        $rows = $stmt->fetchAll();

        $polled = 0;
        foreach ($rows as $row) {
            $polled++;
            $prId = (string) $row['id'];
            $sessionId = (string) $row['session_id'];
            $adapterJobId = (string) ($row['adapter_job_id'] ?? '');
            $attempts = (int) $row['poll_attempts'] + 1;
            $backoffSeconds = min(10, max(2, $attempts * 2));
            $nextPollIso = Clock::fromUnixIso($nowUnix + $backoffSeconds);

            if ($adapterJobId === '') {
                $this->db->transaction(static function (PDO $txPdo) use ($prId, $sessionId, $nowIso): void {
                    $txPdo->prepare(
                        "UPDATE print_requests
                         SET status = 'UNKNOWN', error_code = 'MISSING_ADAPTER_JOB_ID',
                             error_message = 'Thiếu mã job của hàng đợi in.', updated_at = :now
                         WHERE id = :id"
                    )->execute([':now' => $nowIso, ':id' => $prId]);
                    AuditLogger::record($txPdo, 'print.poll', 'UNKNOWN', null, $sessionId, null, [
                        'print_request_id' => $prId,
                    ]);
                });
                continue;
            }

            try {
                $q = $this->adapter->query($adapterJobId, $prId, $scenarioOverride);
                $newStatus = $q['status'];
                $reason = $q['reason'];

                $this->db->transaction(static function (PDO $txPdo) use (
                    $prId,
                    $sessionId,
                    $newStatus,
                    $reason,
                    $attempts,
                    $nextPollIso,
                    $nowIso
                ): void {
                    if (in_array($newStatus, ['COMPLETED', 'FAILED', 'UNKNOWN'], true)) {
                        $errCode = $newStatus === 'UNKNOWN' ? 'PRINTER_UNKNOWN' : ($newStatus === 'FAILED' ? 'PRINT_FAILED' : null);
                        $txPdo->prepare(
                            'UPDATE print_requests
                             SET status = :st, error_code = :code, error_message = :msg,
                                 poll_attempts = :att, updated_at = :now, completed_at = :now
                             WHERE id = :id'
                        )->execute([
                            ':st' => $newStatus,
                            ':code' => $errCode,
                            ':msg' => $reason,
                            ':att' => $attempts,
                            ':now' => $nowIso,
                            ':id' => $prId,
                        ]);
                        AuditLogger::record($txPdo, 'print.status_terminal', $newStatus, null, $sessionId, null, [
                            'print_request_id' => $prId,
                            'reason' => $reason,
                        ]);
                    } else {
                        $txPdo->prepare(
                            'UPDATE print_requests
                             SET status = :st, poll_attempts = :att, next_poll_at = :next, updated_at = :now
                             WHERE id = :id'
                        )->execute([
                            ':st' => $newStatus,
                            ':att' => $attempts,
                            ':next' => $nextPollIso,
                            ':now' => $nowIso,
                            ':id' => $prId,
                        ]);
                    }
                });
            } catch (Throwable $e) {
                // Section 10.1: Queue unreachable -> surface error, keep ID, do NOT conclude COMPLETED
                $this->db->transaction(static function (PDO $txPdo) use ($prId, $e, $attempts, $nextPollIso, $nowIso): void {
                    $txPdo->prepare(
                        'UPDATE print_requests
                         SET error_code = :code, error_message = :msg,
                             poll_attempts = :att, next_poll_at = :next, updated_at = :now
                         WHERE id = :id'
                    )->execute([
                        ':code' => $e instanceof ApiException ? $e->getErrorCode() : 'QUEUE_UNREACHABLE',
                        ':msg' => $e->getMessage(),
                        ':att' => $attempts,
                        ':next' => $nextPollIso,
                        ':now' => $nowIso,
                        ':id' => $prId,
                    ]);
                });
            }
        }

        return $polled;
    }

    /**
     * Startup reconciliation for print_requests left in SUBMITTING state after a worker crash (IT-09).
     * Never resubmits automatically: links adapter_job_id if adapter has deterministic evidence, else marks UNKNOWN.
     */
    public function reconcileInterruptedSubmissions(): int
    {
        $pdo = $this->db->pdo();
        $rows = $pdo->query("SELECT * FROM print_requests WHERE status = 'SUBMITTING'")->fetchAll();
        $count = 0;

        foreach ($rows as $row) {
            $count++;
            $prId = (string) $row['id'];
            $sessionId = (string) $row['session_id'];
            $nowIso = Clock::nowIso();
            $evidence = $this->adapter->reconcileSubmittingRequest($prId);

            $this->db->transaction(static function (PDO $txPdo) use ($prId, $sessionId, $evidence, $nowIso): void {
                if (is_array($evidence) && isset($evidence['adapter_job_id'])) {
                    $txPdo->prepare(
                        "UPDATE print_requests
                         SET status = 'QUEUED', adapter_job_id = :jid, submitted_at = :now, updated_at = :now, next_poll_at = :now
                         WHERE id = :id"
                    )->execute([
                        ':jid' => $evidence['adapter_job_id'],
                        ':now' => $nowIso,
                        ':id' => $prId,
                    ]);
                    AuditLogger::record($txPdo, 'print.reconciled_submitting', 'LINKED_QUEUE_JOB', null, $sessionId, null, [
                        'print_request_id' => $prId,
                        'adapter_job_id' => $evidence['adapter_job_id'],
                    ]);
                } else {
                    $txPdo->prepare(
                        "UPDATE print_requests
                         SET status = 'UNKNOWN',
                             error_code = 'CRASH_DURING_SUBMIT',
                             error_message = 'Tiến trình bị gián đoạn khi đang gửi lệnh in (SUBMITTING). Vui lòng kiểm tra máy in trước khi in lại.',
                             updated_at = :now,
                             completed_at = :now
                         WHERE id = :id"
                    )->execute([
                        ':now' => $nowIso,
                        ':id' => $prId,
                    ]);
                    AuditLogger::record($txPdo, 'print.reconciled_submitting', 'UNKNOWN', null, $sessionId, null, [
                        'print_request_id' => $prId,
                    ]);
                }
            });
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function formatPrintRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'session_id' => (string) $row['session_id'],
            'final_asset_id' => (string) $row['final_asset_id'],
            'copies' => (int) $row['copies'],
            'completed_copies' => $row['status'] === 'COMPLETED' ? (int) ($row['completed_copies'] ?? $row['copies']) : 0,
            'action' => (string) $row['action'],
            'status' => (string) $row['status'],
            'mode' => $row['adapter_mode'],
            'adapter_job_id' => $row['adapter_job_id'] !== null ? (string) $row['adapter_job_id'] : null,
            'error_code' => $row['error_code'] !== null ? (string) $row['error_code'] : null,
            'reason' => $row['error_message'] !== null ? (string) $row['error_message'] : null,
            'resolved_by' => $row['resolved_by'] !== null ? (string) $row['resolved_by'] : null,
            'resolution_outcome' => $row['resolution_outcome'] !== null ? (string) $row['resolution_outcome'] : null,
            'resolution_note' => $row['resolution_note'] !== null ? (string) $row['resolution_note'] : null,
            'resolved_at' => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            'created_at' => (string) $row['created_at'],
            'submitted_at' => $row['submitted_at'] !== null ? (string) $row['submitted_at'] : null,
            'updated_at' => (string) $row['updated_at'],
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
        ];
    }
}

