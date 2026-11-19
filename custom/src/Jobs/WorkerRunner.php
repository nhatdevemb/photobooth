<?php

declare(strict_types=1);

namespace Photobooth\Operator\Jobs;

use PDO;
use Photobooth\Operator\Catalog\TemplateCatalog;
use Photobooth\Operator\Catalog\LutCatalog;
use Photobooth\Operator\Camera\CameraAdapterInterface;
use Photobooth\Operator\Camera\BrowserCameraAdapter;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Image\BeautyService;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Printer\PrintService;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;
use Throwable;

final class WorkerRunner
{
    private const NON_RETRYABLE_RENDER_CODES = [
        'INVALID_LUT',
        'INVALID_TEMPLATE',
        'INVALID_ICC_PROFILE',
        'CORRUPT_JPEG',
        'INVALID_JPEG',
        'IMAGE_TOO_LARGE',
        'IMAGE_PIXELS_EXCEEDED',
        'MISSING_PHOTOS',
        'MISSING_PHOTO_FILE',
        'SOURCE_HASH_MISMATCH',
        'INTEGRITY_ERROR',
        'DISK_LOW',
    ];

    private TemplateCatalog $catalog;
    private LutCatalog $luts;
    private Database $db;
    private AppConfig $config;
    private AssetStore $assetStore;
    private CameraAdapterInterface $camera;
    private Renderer $renderer;
    private PrintService $printService;
    private StructuredLogger $logger;
    private string $workerId;
    /** @var resource|null */
    private $lockHandle = null;

    private BeautyService $beauty;

    public function __construct(
        Database $db,
        AppConfig $config,
        AssetStore $assetStore,
        CameraAdapterInterface $camera,
        Renderer $renderer,
        PrintService $printService,
        StructuredLogger $logger,
        TemplateCatalog $catalog,
        LutCatalog $luts,
        ?string $workerId = null,
        ?BeautyService $beauty = null
    )
    {
        $this->beauty = $beauty ?? new BeautyService($db, $config, $assetStore, $renderer);
        $this->catalog = $catalog;
        $this->luts = $luts;
        $this->db = $db;
        $this->config = $config;
        $this->assetStore = $assetStore;
        $this->camera = $camera;
        $this->renderer = $renderer;
        $this->printService = $printService;
        $this->logger = $logger;
        $this->workerId = $workerId ?? ('worker-' . gethostname() . '-' . getmypid());
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /**
     * Acquire OS-level singleton lock (`flock` non-blocking).
     * Prevents two workers from running concurrently (IT-15).
     */
    public function acquireSingletonLock(): bool
    {
        if ($this->lockHandle !== null) {
            return true;
        }
        $lockDir = $this->config->dataDir . '/locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0750, true);
        }
        $lockFile = $lockDir . '/worker.lock';
        $fp = @fopen($lockFile, 'c+');
        if ($fp === false) {
            return false;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        ftruncate($fp, 0);
        fwrite($fp, json_encode([
            'worker_id' => $this->workerId,
            'pid' => getmypid(),
            'started_at' => Clock::nowIso(),
        ]) . "\n");
        fflush($fp);
        $this->lockHandle = $fp;
        return true;
    }

    public function releaseSingletonLock(): void
    {
        if ($this->lockHandle !== null) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    public function __destruct()
    {
        $this->releaseSingletonLock();
    }

    public function updateHeartbeat(?string $activeJobId = null, string $status = 'RUNNING'): void
    {
        $nowIso = Clock::nowIso();
        $pdo = $this->db->pdo();

        $pdo->prepare(
            'INSERT INTO worker_state (worker_id, pid, hostname, mode, started_at, heartbeat_at, active_job_id, status)
             VALUES (:wid, :pid, :host, :mode, :now, :now, :jid, :st)
             ON CONFLICT(worker_id) DO UPDATE SET
                 pid = excluded.pid,
                 mode = excluded.mode,
                 heartbeat_at = excluded.heartbeat_at,
                 active_job_id = excluded.active_job_id,
                 status = excluded.status'
        )->execute([
            ':wid' => $this->workerId,
            ':pid' => getmypid(),
            ':host' => (string) gethostname(),
            ':mode' => $this->config->isMockMode() ? 'mock' : 'hardware',
            ':now' => $nowIso,
            ':jid' => $activeJobId,
            ':st' => $status,
        ]);

        if ($activeJobId !== null) {
            $pdo->prepare('UPDATE jobs SET heartbeat_at = :now WHERE id = :id')
                ->execute([':now' => $nowIso, ':id' => $activeJobId]);
        }
    }

    /**
     * Reconcile interrupted jobs, print submissions, tombstoned sessions, and orphan files on startup.
     *
     * @return array<string, mixed>
     */
    public function reconcileStartup(): array
    {
        $pdo = $this->db->pdo();
        $nowIso = Clock::nowIso();

        // 1. Reconcile jobs left in RUNNING state
        $runningJobs = $pdo->query("SELECT * FROM jobs WHERE status = 'RUNNING'")->fetchAll();
        $reconciledJobs = 0;

        foreach ($runningJobs as $job) {
            $reconciledJobs++;
            $jobId = (string) $job['id'];
            $sessionId = (string) $job['session_id'];
            $type = (string) $job['type'];
            $attempt = (int) $job['attempt'];
            $maxAttempts = (int) $job['max_attempts'];
            $oldPid = $job['worker_pid'] !== null ? (int) $job['worker_pid'] : 0;

            // Ensure old process is stopped if it belonged to a previous worker PID
            if ($oldPid > 0 && $oldPid !== getmypid() && function_exists('posix_kill')) {
                if (@posix_kill($oldPid, 0)) {
                    @posix_kill($oldPid, 15);
                    usleep(50_000);
                    @posix_kill($oldPid, 9);
                }
            }

            if ($type === 'capture' || $type === 'retake') {
                // Never auto-retry capture/retake after crash because shutter may have fired
                $this->failCaptureOrRetakeJob(
                    $jobId,
                    $sessionId,
                    'WORKER_CRASHED_DURING_CAPTURE',
                    'Tiến trình worker bị gián đoạn khi đang chụp ảnh. Đã giữ nguyên trạng thái slot trước đó; không tự động chụp lại.'
                );
            } elseif ($type === 'render') {
                if ($attempt < $maxAttempts) {
                    $this->db->transaction(static function (PDO $txPdo) use ($jobId, $sessionId, $attempt, $nowIso): void {
                        $txPdo->prepare(
                            "UPDATE jobs
                             SET status = 'QUEUED', phase = 'queued', progress = 0, attempt = :att,
                                 worker_id = NULL, worker_pid = NULL, heartbeat_at = :now
                             WHERE id = :id"
                        )->execute([
                            ':att' => $attempt + 1,
                            ':now' => $nowIso,
                            ':id' => $jobId,
                        ]);
                        AuditLogger::record($txPdo, 'render.retry_scheduled_on_startup', 'QUEUED', null, $sessionId, $jobId, [
                            'next_attempt' => $attempt + 1,
                        ]);
                    });
                } else {
                    $this->failRenderJob(
                        $jobId,
                        $sessionId,
                        'WORKER_CRASHED_DURING_RENDER',
                        'Tiến trình xử lý ảnh bị gián đoạn quá số lần thử lại.'
                    );
                }
            } elseif ($type === 'delete_session') {
                $pdo->prepare(
                    "UPDATE jobs SET status = 'QUEUED', worker_id = NULL, worker_pid = NULL, heartbeat_at = :now WHERE id = :id"
                )->execute([':now' => $nowIso, ':id' => $jobId]);
            }
        }

        // 2. Ensure any tombstoned session without a queued delete_session job gets one (IT-16)
        $tombstoned = $pdo->query(
            "SELECT s.id, s.revision FROM sessions s
             WHERE s.deleted_at IS NOT NULL
               AND NOT EXISTS (
                   SELECT 1 FROM jobs j WHERE j.session_id = s.id AND j.type = 'delete_session' AND j.status IN ('QUEUED', 'RUNNING')
               )"
        )->fetchAll();
        foreach ($tombstoned as $tRow) {
            $sid = (string) $tRow['id'];
            $jid = Uuid::v4();
            $pdo->prepare(
                "INSERT INTO jobs (id, session_id, type, status, phase, progress, accepted_revision, attempt, max_attempts, payload, created_at)
                 VALUES (:id, :sid, 'delete_session', 'QUEUED', 'tombstoned', 0, :rev, 1, 2, :payload, :now)"
            )->execute([
                ':id' => $jid,
                ':sid' => $sid,
                ':rev' => (int) $tRow['revision'],
                ':payload' => json_encode(['session_id' => $sid]) ?: '{}',
                ':now' => $nowIso,
            ]);
        }

        // 3. Reconcile interrupted SUBMITTING print requests (IT-09)
        $reconciledPrints = $this->printService->reconcileInterruptedSubmissions();

        // 4. Clean pending directories and isolate unreferenced orphan files
        $orphanStats = $this->assetStore->reconcileStartupOrphans($pdo);

        $this->updateHeartbeat(null, 'IDLE');

        return [
            'reconciled_jobs' => $reconciledJobs,
            'reconciled_prints' => $reconciledPrints,
            'cleaned_pending_dirs' => $orphanStats['cleaned_pending_dirs'],
            'quarantined_orphans' => $orphanStats['quarantined_orphans'],
        ];
    }

    /**
     * Execute one worker tick:
     * 1. Update heartbeat
     * 2. Claim and run one QUEUED job (if any)
     * 3. Submit one PENDING print request (if any)
     * 4. Poll due QUEUED/PRINTING print requests
     *
     * @return array{job_processed: ?string, print_submitted: ?string, prints_polled: int}
     */
    public function runTick(?string $faultScenarioOverride = null, ?string $printScenarioOverride = null): array
    {
        $this->updateHeartbeat(null, 'IDLE');

        $jobProcessed = $this->processNextQueuedJob($faultScenarioOverride);
        $printSubmitted = $this->printService->processNextPendingPrint($printScenarioOverride);
        $printsPolled = $this->printService->pollActivePrints($printScenarioOverride);

        $this->updateHeartbeat(null, 'IDLE');

        return [
            'job_processed' => $jobProcessed !== null ? (string) $jobProcessed['id'] : null,
            'print_submitted' => $printSubmitted !== null ? (string) $printSubmitted['id'] : null,
            'prints_polled' => $printsPolled,
        ];
    }

    /**
     * Drain all currently queued jobs and pending/active print requests (helpful in tests and CLI).
     */
    public function drainAll(?string $faultScenarioOverride = null, ?string $printScenarioOverride = null, int $maxTicks = 20): void
    {
        for ($i = 0; $i < $maxTicks; $i++) {
            $tick = $this->runTick($faultScenarioOverride, $printScenarioOverride);
            if ($tick['job_processed'] === null && $tick['print_submitted'] === null && $tick['prints_polled'] === 0) {
                break;
            }
        }
    }

    /**
     * Claim and execute the oldest QUEUED job.
     *
     * @return ?array<string, mixed>
     */
    public function processNextQueuedJob(?string $faultScenarioOverride = null): ?array
    {
        $claimed = $this->db->transaction(function (PDO $txPdo): ?array {
            $stmt = $txPdo->query(
                "SELECT * FROM jobs WHERE status = 'QUEUED' ORDER BY created_at ASC LIMIT 1"
            );
            $row = $stmt !== false ? $stmt->fetch() : false;
            if (!is_array($row)) {
                return null;
            }

            $jobId = (string) $row['id'];
            $nowIso = Clock::nowIso();

            $upd = $txPdo->prepare(
                "UPDATE jobs
                 SET status = 'RUNNING',
                     worker_id = :wid,
                     worker_pid = :pid,
                     started_at = COALESCE(started_at, :now),
                     heartbeat_at = :now
                 WHERE id = :id AND status = 'QUEUED'"
            );
            $upd->execute([
                ':wid' => $this->workerId,
                ':pid' => getmypid(),
                ':now' => $nowIso,
                ':id' => $jobId,
            ]);

            if ($upd->rowCount() !== 1) {
                return null;
            }

            return $row;
        });

        if ($claimed === null) {
            return null;
        }

        $jobId = (string) $claimed['id'];
        $type = (string) $claimed['type'];
        $this->updateHeartbeat($jobId, 'BUSY');

        if ($type === 'capture' || $type === 'retake') {
            $this->executeCaptureOrRetakeJob($claimed, $faultScenarioOverride);
        } elseif ($type === 'render') {
            $this->executeRenderJob($claimed, $faultScenarioOverride);
        } elseif ($type === 'delete_session') {
            $this->executeDeleteSessionJob($claimed);
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        $finalJob = $stmt->fetch();
        return is_array($finalJob) ? $finalJob : null;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function executeCaptureOrRetakeJob(array $job, ?string $faultScenarioOverride = null): void
    {
        $jobId = (string) $job['id'];
        $sessionId = (string) $job['session_id'];
        $acceptedRevision = (int) $job['accepted_revision'];
        $attempt = (int) $job['attempt'];
        $payload = json_decode((string) $job['payload'], true) ?: [];
        $slot = (int) ($payload['slot'] ?? 1);
        $version = (int) ($payload['version'] ?? 1);
        $mode = (string) ($payload['mode'] ?? 'capture');

        $pendingDir = $this->assetStore->pendingJobDir($sessionId, $jobId, $attempt);
        $publishedPath = null;

        try {
            $this->assetStore->assertDiskSpaceAvailable();

            // Phase 1: Countdown (3 seconds in production, configurable delay in mock)
            $browserCapture = ($payload['camera_mode'] ?? '') === 'browser';
            for ($remaining = $browserCapture ? 0 : $this->config->countdownSeconds; $remaining > 0; $remaining--) {
                $this->updateJobProgress($jobId, 'countdown-' . $remaining, 25);
                $this->updateHeartbeat($jobId, 'COUNTDOWN');
                $delay = $this->config->cameraAdapter === 'mock' ? $this->config->mockCountdownDelayMs * 1000 : 1000000;
                if ($delay > 0) {
                    usleep($delay);
                }
            }
            $this->updateJobProgress($jobId, 'capturing', 60);

            $deadline = Clock::nowUnix() + $this->config->captureTimeoutSeconds;
            if ($browserCapture) {
                $captureData = (new BrowserCameraAdapter($this->renderer))->capture($pendingDir, $slot, $version, $deadline);
                if (!hash_equals((string) ($payload['frame_sha256'] ?? ''), $captureData['sha256'])) {
                    throw new ApiException('HASH_MISMATCH', 'Ảnh camera đã thay đổi trước khi được xử lý.', 409);
                }
            } else {
                if (isset($payload['camera_mode']) && $payload['camera_mode'] !== $this->camera->getMode()) {
                    throw new ApiException('INVALID_CAMERA_MODE', 'Chế độ camera đã thay đổi. Hãy khôi phục cấu hình và chụp lại.', 409);
                }
                $captureData = $this->camera->capture($pendingDir, $slot, $version, $deadline, $faultScenarioOverride);
            }

            // Publish from pending to immutable originals/slot-{slot}/v{version}.jpg
            $targetRelPath = $this->assetStore->originalPhotoRelativePath($sessionId, $slot, $version);
            $publishedHash = $this->assetStore->publishImmutableFile($captureData['temp_path'], $targetRelPath);
            $publishedPath = $targetRelPath;

            if (!hash_equals($captureData['sha256'], $publishedHash)) {
                throw new ApiException('HASH_MISMATCH', 'Mã băm SHA-256 của ảnh chụp không khớp sau khi lưu.', 500);
            }

            $photoId = Uuid::v4();
            $nowIso = Clock::nowIso();

            // Commit metadata only if session still owns active_job_id and accepted_revision
            $this->db->transaction(function (PDO $txPdo) use (
                $jobId,
                $sessionId,
                $acceptedRevision,
                $slot,
                $version,
                $mode,
                $photoId,
                $targetRelPath,
                $captureData,
                $nowIso
            ): void {
                $sStmt = $txPdo->prepare('SELECT * FROM sessions WHERE id = :id AND deleted_at IS NULL');
                $sStmt->execute([':id' => $sessionId]);
                $sRow = $sStmt->fetch();

                if (
                    !is_array($sRow)
                    || (string) ($sRow['active_job_id'] ?? '') !== $jobId
                    || (int) $sRow['revision'] !== $acceptedRevision
                ) {
                    throw new ApiException(
                        'STALE_JOB_OWNERSHIP',
                        'Tác vụ chụp không còn quyền sở hữu phiên hiện tại.',
                        409
                    );
                }

                $txPdo->prepare(
                    'INSERT INTO photos (id, session_id, slot, version, path, sha256, width, height, bytes, exif_orientation, created_at)
                     VALUES (:id, :sid, :slot, :ver, :path, :sha, :w, :h, :bytes, :orient, :now)'
                )->execute([
                    ':id' => $photoId,
                    ':sid' => $sessionId,
                    ':slot' => $slot,
                    ':ver' => $version,
                    ':path' => $targetRelPath,
                    ':sha' => $captureData['sha256'],
                    ':w' => $captureData['width'],
                    ':h' => $captureData['height'],
                    ':bytes' => $captureData['bytes'],
                    ':orient' => $captureData['exif_orientation'],
                    ':now' => $nowIso,
                ]);

                $txPdo->prepare(
                    'UPDATE session_slots SET current_photo_id = :pid, updated_at = :now WHERE session_id = :sid AND slot = :slot'
                )->execute([
                    ':pid' => $photoId,
                    ':now' => $nowIso,
                    ':sid' => $sessionId,
                    ':slot' => $slot,
                ]);

                // Invalidate any previous renders now that a new slot photo is committed (TC-10)
                $txPdo->prepare('UPDATE renders SET valid = 0 WHERE session_id = :sid')
                    ->execute([':sid' => $sessionId]);

                // Job completion does NOT increment session revision again (Section 4.3 rule 5)
                $txPdo->prepare(
                    "UPDATE sessions
                     SET state = 'CAPTURED', pre_job_state = NULL, current_render_id = NULL, active_job_id = NULL, updated_at = :now
                     WHERE id = :id"
                )->execute([
                    ':now' => $nowIso,
                    ':id' => $sessionId,
                ]);

                $resultJson = json_encode([
                    'photo_id' => $photoId,
                    'slot' => $slot,
                    'version' => $version,
                    'sha256' => $captureData['sha256'],
                    'width' => $captureData['width'],
                    'height' => $captureData['height'],
                    'bytes' => $captureData['bytes'],
                ], JSON_UNESCAPED_SLASHES) ?: '{}';

                $txPdo->prepare(
                    "UPDATE jobs
                     SET status = 'SUCCEEDED', phase = 'completed', progress = 100, result = :res,
                         heartbeat_at = :now, completed_at = :now
                     WHERE id = :id"
                )->execute([
                    ':res' => $resultJson,
                    ':now' => $nowIso,
                    ':id' => $jobId,
                ]);

                if (($captureData['icc_status'] ?? '') === 'assumed_srgb') {
                    AuditLogger::record($txPdo, 'photo.icc_assumed_srgb', 'ASSUMED_SRGB', null, $sessionId, $jobId, [
                        'photo_id' => $photoId,
                        'slot' => $slot,
                        'version' => $version,
                    ]);
                }

                AuditLogger::record($txPdo, 'slot.' . $mode . '_succeeded', 'SUCCEEDED', null, $sessionId, $jobId, [
                    'photo_id' => $photoId,
                    'slot' => $slot,
                    'version' => $version,
                    'sha256' => $captureData['sha256'],
                ]);
            });
        } catch (Throwable $e) {
            $code = $e instanceof ApiException ? $e->getErrorCode() : 'CAPTURE_FAILED';
            if ($publishedPath !== null) {
                // Only this attempt's uncommitted file may be moved; committed photos stay immutable.
                $this->db->transaction(function (PDO $pdo) use ($publishedPath): void {
                    $this->assetStore->quarantineUnreferencedPhoto($pdo, $publishedPath);
                });
            }
            $this->failCaptureOrRetakeJob($jobId, $sessionId, $code, $e->getMessage());
            $this->logger->error('capture_job_failed', $code, [
                'session_id' => $sessionId,
                'job_id' => $jobId,
                'slot' => $slot,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->assetStore->removeDirectoryRecursive(dirname($pendingDir));
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function executeRenderJob(array $job, ?string $faultScenarioOverride = null): void
    {
        $jobId = (string) $job['id'];
        $sessionId = (string) $job['session_id'];
        $acceptedRevision = (int) $job['accepted_revision'];
        $attempt = (int) $job['attempt'];
        $maxAttempts = (int) $job['max_attempts'];
        $payload = json_decode((string) $job['payload'], true) ?: [];

        $lutId = (string) ($payload['lut_id'] ?? 'original');
        $lutVersion = (string) ($payload['lut_version'] ?? '1.0.0');
        $lutFile = $payload['lut_file'] ?? null;
        $templateId = (string) ($payload['template_id'] ?? 'vertical-three-v1');
        $photosSnapshot = is_array($payload['photos'] ?? null) ? $payload['photos'] : [];

        $pendingDir = $this->assetStore->pendingJobDir($sessionId, $jobId, $attempt);

        try {
            $this->assetStore->assertDiskSpaceAvailable();
            $this->updateJobProgress($jobId, 'rendering', 35);

            $scenario = $faultScenarioOverride ?? $this->config->mockFaultScenario;
            if ($scenario === 'render_transient_crash' && $attempt === 1) {
                throw new ApiException('RENDER_PROCESS_CRASH', 'Tiến trình render tạm thời bị ngắt (attempt 1).', 500);
            }

            $manifestPhotos = [];
            $sourcePhotoIds = [];
            foreach ($photosSnapshot as $p) {
                $absPhotoPath = $this->assetStore->absolutePath((string) $p['path']);
                $manifestPhotos[] = [
                    'slot' => (int) $p['slot'],
                    'path' => $absPhotoPath,
                    'sha256' => (string) $p['sha256'],
                ];
                $sourcePhotoIds[] = (string) $p['photo_id'];
            }

            $pendingPrint = $pendingDir . '/print.jpg';
            $pendingPreview = $pendingDir . '/preview.jpg';
            $lutPath = $lutFile !== null ? ($this->config->customRoot . '/presets/' . $lutFile) : null;
            $lutIntensity = 100;
            if (isset($payload['lut_snapshot'])) {
                $item = $this->luts->resolve($lutId, $lutVersion);
                $snapshot = $payload['lut_snapshot'];
                if ($snapshot != ['id'=>$item['id'], 'version'=>$item['version'], 'sha256'=>$item['sha256'], 'intensity'=>$item['intensity']]) {
                    throw new ApiException('INTEGRITY_ERROR', 'Snapshot LUT đã bị thay đổi.', 409);
                }
                $lutPath = $this->luts->filePath($item);
                $lutIntensity = $item['intensity'];
            }

            $manifestData = [
                'photos' => $manifestPhotos,
                'lut_id' => $lutId,
                'lut_version' => $lutVersion,
                'lut_path' => $lutPath,
                'lut_intensity' => $lutIntensity,
                'lut_sha256' => $payload['lut_sha256'] ?? null,
                'beauty_preset' => $payload['beauty_preset'] ?? 'off',
                'beauty_config' => $payload['beauty_config'] ?? null,
                'template' => $payload['template'] ?? $this->catalog->renderSnapshot($templateId, '1.0.0'),
                'max_jpeg_bytes' => $this->config->maxJpegBytes,
                'max_image_pixels' => $this->config->maxImagePixels,
                'output_print_path' => $pendingPrint,
                'output_preview_path' => $pendingPreview,
            ];

            $beauty = $manifestData['beauty_config'];
            if ($manifestData['beauty_preset'] !== 'off' && ($beauty === null
                || ($beauty['smooth'] || $beauty['brighten'] || $beauty['tone']))) {
                $manifestData['beauty_cache_dir'] = $this->beauty->cacheDir($sessionId);
                $manifestData['retry_lkmagic'] = $payload['retry_lkmagic'] ?? false;
                $manifestData['beauty_progress_key'] = BeautyService::progressKey($manifestData);
            }
            $renderResult = $this->renderer->renderFromManifest($pendingDir, $manifestData, $jobId);
            $this->updateJobProgress($jobId, 'publishing', 85);

            $renderId = Uuid::v4();
            $printRelPath = $this->assetStore->renderPrintRelativePath($sessionId, $renderId);
            $previewRelPath = $this->assetStore->renderPreviewRelativePath($sessionId, $renderId);

            $publishedPrintSha = $this->assetStore->publishImmutableFile($pendingPrint, $printRelPath);
            $publishedPreviewSha = $this->assetStore->publishImmutableFile($pendingPreview, $previewRelPath);

            if (!hash_equals((string) $renderResult['sha256'], $publishedPrintSha)) {
                throw new ApiException('HASH_MISMATCH', 'Mã băm SHA-256 của bản print.jpg không khớp sau khi publish.', 500);
            }

            $nowIso = Clock::nowIso();

            $this->db->transaction(function (PDO $txPdo) use (
                $jobId,
                $sessionId,
                $acceptedRevision,
                $renderId,
                $lutId,
                $lutVersion,
                $templateId,
                $sourcePhotoIds,
                $printRelPath,
                $previewRelPath,
                $publishedPrintSha,
                $publishedPreviewSha,
                $renderResult,
                $nowIso
            ): void {
                $sStmt = $txPdo->prepare('SELECT * FROM sessions WHERE id = :id AND deleted_at IS NULL');
                $sStmt->execute([':id' => $sessionId]);
                $sRow = $sStmt->fetch();

                if (
                    !is_array($sRow)
                    || (string) ($sRow['active_job_id'] ?? '') !== $jobId
                    || (int) $sRow['revision'] !== $acceptedRevision
                ) {
                    throw new ApiException(
                        'STALE_JOB_OWNERSHIP',
                        'Tác vụ render không còn quyền sở hữu phiên hiện tại.',
                        409
                    );
                }

                $txPdo->prepare('UPDATE renders SET valid = 0 WHERE session_id = :sid')
                    ->execute([':sid' => $sessionId]);

                $txPdo->prepare(
                    'INSERT INTO renders
                     (id, session_id, job_id, lut_id, lut_version, template_id, template_version, pipeline_version,
                      source_photo_ids, input_signature, image_path, preview_path, sha256, preview_sha256,
                      width, height, duration_ms, valid, created_at)
                     VALUES (:id, :sid, :jid, :lut, :lut_ver, :tpl, :tpl_ver, :pipe_ver,
                             :src_ids, :sig, :img_path, :prev_path, :sha, :prev_sha,
                             :w, :h, :dur, 1, :now)'
                )->execute([
                    ':id' => $renderId,
                    ':sid' => $sessionId,
                    ':jid' => $jobId,
                    ':lut' => $lutId,
                    ':lut_ver' => $lutVersion,
                    ':tpl' => $templateId,
                    ':tpl_ver' => (string) ($renderResult['template_version'] ?? '1.0.0'),
                    ':pipe_ver' => (string) ($renderResult['pipeline_version'] ?? '1.0.0'),
                    ':src_ids' => json_encode($sourcePhotoIds, JSON_UNESCAPED_SLASHES) ?: '[]',
                    ':sig' => (string) $renderResult['input_signature'],
                    ':img_path' => $printRelPath,
                    ':prev_path' => $previewRelPath,
                    ':sha' => $publishedPrintSha,
                    ':prev_sha' => $publishedPreviewSha,
                    ':w' => (int) $renderResult['width'],
                    ':h' => (int) $renderResult['height'],
                    ':dur' => (int) ($renderResult['duration_ms'] ?? 0),
                    ':now' => $nowIso,
                ]);

                // Transition session to REVIEW without incrementing revision
                $txPdo->prepare(
                    "UPDATE sessions
                     SET state = 'REVIEW', pre_job_state = NULL, current_render_id = :rid, active_job_id = NULL, updated_at = :now
                     WHERE id = :id"
                )->execute([
                    ':rid' => $renderId,
                    ':now' => $nowIso,
                    ':id' => $sessionId,
                ]);

                $resultJson = json_encode([
                    'render_id' => $renderId,
                    'lut_id' => $lutId,
                    'template_id' => $templateId,
                    'sha256' => $publishedPrintSha,
                    'preview_sha256' => $publishedPreviewSha,
                    'input_signature' => (string) $renderResult['input_signature'],
                    'width' => (int) $renderResult['width'],
                    'height' => (int) $renderResult['height'],
                    'duration_ms' => (int) ($renderResult['duration_ms'] ?? 0),
                    'preview_url' => '/custom/api/assets/' . $renderId . '?variant=preview',
                    'beauty_audit' => $renderResult['beauty_audit'] ?? [],
                    'beauty_fallback' => $renderResult['beauty_fallback'] ?? null,
                ], JSON_UNESCAPED_SLASHES) ?: '{}';

                $txPdo->prepare(
                    "UPDATE jobs
                     SET status = 'SUCCEEDED', phase = 'completed', progress = 100, result = :res,
                         heartbeat_at = :now, completed_at = :now
                     WHERE id = :id"
                )->execute([
                    ':res' => $resultJson,
                    ':now' => $nowIso,
                    ':id' => $jobId,
                ]);

                if (isset($renderResult['icc_audit']) && is_array($renderResult['icc_audit'])) {
                    foreach ($renderResult['icc_audit'] as $iccNote) {
                        if (is_array($iccNote) && ($iccNote['icc_status'] ?? '') === 'assumed_srgb') {
                            AuditLogger::record($txPdo, 'render.icc_assumed_srgb', 'ASSUMED_SRGB', null, $sessionId, $jobId, [
                                'slot' => $iccNote['slot'] ?? null,
                                'render_id' => $renderId,
                            ]);
                        }
                    }
                }

                AuditLogger::record($txPdo, 'render.succeeded', 'SUCCEEDED', null, $sessionId, $jobId, [
                    'render_id' => $renderId,
                    'lut_id' => $lutId,
                    'sha256' => $publishedPrintSha,
                    'duration_ms' => (int) ($renderResult['duration_ms'] ?? 0),
                ]);
            });
        } catch (Throwable $e) {
            $code = $e instanceof ApiException ? $e->getErrorCode() : 'RENDER_FAILED';
            $isRetryable = !in_array($code, self::NON_RETRYABLE_RENDER_CODES, true) && $attempt < $maxAttempts;

            if ($isRetryable) {
                $nowIso = Clock::nowIso();
                $this->db->transaction(static function (PDO $txPdo) use ($jobId, $sessionId, $attempt, $code, $nowIso): void {
                    $txPdo->prepare(
                        "UPDATE jobs
                         SET status = 'QUEUED', phase = 'queued', progress = 0, attempt = :att,
                             worker_id = NULL, worker_pid = NULL, heartbeat_at = :now
                         WHERE id = :id"
                    )->execute([
                        ':att' => $attempt + 1,
                        ':now' => $nowIso,
                        ':id' => $jobId,
                    ]);
                    AuditLogger::record($txPdo, 'render.retry_scheduled', $code, null, $sessionId, $jobId, [
                        'next_attempt' => $attempt + 1,
                    ]);
                });
            } else {
                $this->failRenderJob($jobId, $sessionId, $code, $e->getMessage());
            }

            $this->logger->error('render_job_error', $code, [
                'session_id' => $sessionId,
                'job_id' => $jobId,
                'attempt' => $attempt,
                'retrying' => $isRetryable,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->assetStore->removeDirectoryRecursive($pendingDir);
        }
    }

    /**
     * Phase 2 of two-phase session deletion: remove session directory on disk and purge session rows,
     * while preserving audit_events for 30 days.
     *
     * @param array<string, mixed> $job
     */
    private function executeDeleteSessionJob(array $job): void
    {
        $jobId = (string) $job['id'];
        $sessionId = (string) $job['session_id'];
        $nowIso = Clock::nowIso();

        $this->assetStore->removeSessionDirectory($sessionId);

        $this->db->transaction(static function (PDO $txPdo) use ($sessionId, $jobId, $nowIso): void {
            AuditLogger::record($txPdo, 'session.deleted_permanently', 'SUCCEEDED', null, $sessionId, $jobId);
            $txPdo->prepare('DELETE FROM sessions WHERE id = :id')->execute([':id' => $sessionId]);
        });

        $this->logger->info('session_deleted_permanently', 'OK', [
            'session_id' => $sessionId,
            'job_id' => $jobId,
        ]);
    }

    private function failCaptureOrRetakeJob(string $jobId, string $sessionId, string $code, string $message): void
    {
        $nowIso = Clock::nowIso();
        $this->db->transaction(static function (PDO $txPdo) use ($jobId, $sessionId, $code, $message, $nowIso): void {
            $sStmt = $txPdo->prepare('SELECT * FROM sessions WHERE id = :id');
            $sStmt->execute([':id' => $sessionId]);
            $sRow = $sStmt->fetch();

            if (is_array($sRow) && (string) ($sRow['active_job_id'] ?? '') === $jobId) {
                $cntStmt = $txPdo->prepare(
                    'SELECT COUNT(*) FROM session_slots WHERE session_id = :sid AND current_photo_id IS NOT NULL'
                );
                $cntStmt->execute([':sid' => $sessionId]);
                $filledCount = (int) $cntStmt->fetchColumn();

                $restoreState = (string) ($sRow['pre_job_state'] ?? '');
                if ($restoreState === '' || !in_array($restoreState, ['NEW', 'CAPTURED', 'REVIEW'], true)) {
                    $restoreState = $filledCount > 0 ? 'CAPTURED' : 'NEW';
                }

                $txPdo->prepare(
                    'UPDATE sessions
                     SET state = :st, pre_job_state = NULL, active_job_id = NULL, updated_at = :now
                     WHERE id = :id'
                )->execute([
                    ':st' => $restoreState,
                    ':now' => $nowIso,
                    ':id' => $sessionId,
                ]);
            }

            $errJson = json_encode(['code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE) ?: '{}';
            $txPdo->prepare(
                "UPDATE jobs
                 SET status = 'FAILED', phase = 'failed', error = :err, heartbeat_at = :now, completed_at = :now
                 WHERE id = :id"
            )->execute([
                ':err' => $errJson,
                ':now' => $nowIso,
                ':id' => $jobId,
            ]);

            AuditLogger::record($txPdo, 'slot.capture_failed', $code, null, $sessionId, $jobId, [
                'error' => $message,
            ]);
        });
    }

    private function failRenderJob(string $jobId, string $sessionId, string $code, string $message): void
    {
        $nowIso = Clock::nowIso();
        $this->db->transaction(static function (PDO $txPdo) use ($jobId, $sessionId, $code, $message, $nowIso): void {
            $sStmt = $txPdo->prepare('SELECT * FROM sessions WHERE id = :id');
            $sStmt->execute([':id' => $sessionId]);
            $sRow = $sStmt->fetch();

            if (is_array($sRow) && (string) ($sRow['active_job_id'] ?? '') === $jobId) {
                $txPdo->prepare(
                    "UPDATE sessions
                     SET state = 'CAPTURED', pre_job_state = NULL, current_render_id = NULL, active_job_id = NULL, updated_at = :now
                     WHERE id = :id"
                )->execute([
                    ':now' => $nowIso,
                    ':id' => $sessionId,
                ]);
            }

            $errJson = json_encode(['code' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE) ?: '{}';
            $txPdo->prepare(
                "UPDATE jobs
                 SET status = 'FAILED', phase = 'failed', error = :err, heartbeat_at = :now, completed_at = :now
                 WHERE id = :id"
            )->execute([
                ':err' => $errJson,
                ':now' => $nowIso,
                ':id' => $jobId,
            ]);

            AuditLogger::record($txPdo, 'render.failed', $code, null, $sessionId, $jobId, [
                'error' => $message,
            ]);
        });
    }

    private function updateJobProgress(string $jobId, string $phase, int $progress): void
    {
        $nowIso = Clock::nowIso();
        $this->db->pdo()->prepare(
            'UPDATE jobs SET phase = :ph, progress = :pr, heartbeat_at = :now WHERE id = :id'
        )->execute([
            ':ph' => $phase,
            ':pr' => $progress,
            ':now' => $nowIso,
            ':id' => $jobId,
        ]);
    }
}

