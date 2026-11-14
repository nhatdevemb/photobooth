<?php

declare(strict_types=1);

namespace Photobooth\Operator\Session;

use PDO;
use Photobooth\Operator\Catalog\TemplateCatalog;
use Photobooth\Operator\Catalog\LutCatalog;
use Photobooth\Operator\Camera\BrowserCameraAdapter;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Share\ShareService;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\AuditLogger;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;

final class SessionService
{
    public const BEAUTY_PRESETS = [
        'off' => ['smooth' => 0, 'brighten' => 0, 'tone' => 0],
        'natural' => ['smooth' => 20, 'brighten' => 5, 'tone' => 10],
        'soft' => ['smooth' => 40, 'brighten' => 10, 'tone' => 15],
        'korean-glow' => ['smooth' => 55, 'brighten' => 15, 'tone' => 20],
    ];

    private TemplateCatalog $catalog;
    private LutCatalog $luts;
    private Database $db;
    private AppConfig $config;
    private AssetStore $assetStore;
    private ShareService $shareService;
    private StructuredLogger $logger;

    public function __construct(
        Database $db,
        AppConfig $config,
        AssetStore $assetStore,
        ShareService $shareService,
        StructuredLogger $logger,
        TemplateCatalog $catalog,
        LutCatalog $luts
    )
    {
        $this->catalog = $catalog;
        $this->luts = $luts;
        $this->db = $db;
        $this->config = $config;
        $this->assetStore = $assetStore;
        $this->shareService = $shareService;
        $this->logger = $logger;
    }

    /**
     * POST /custom/api/sessions
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function createSession(string $actorId, string $idempotencyKey): array
    {
        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload(['op' => 'create_session']);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.create',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        return $this->db->transaction(function (PDO $pdo) use ($actorId, $idemKey, $payloadHash): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.create', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $activeCheck = $pdo->query(
                "SELECT id, state FROM sessions WHERE state NOT IN ('CLOSED', 'CANCELLED') AND deleted_at IS NULL LIMIT 1"
            );
            $activeRow = $activeCheck !== false ? $activeCheck->fetch() : false;
            if (is_array($activeRow)) {
                throw new ApiException(
                    'ACTIVE_SESSION_EXISTS',
                    'Đang có một phiên chụp chưa kết thúc. Vui lòng hoàn tất, đóng hoặc hủy phiên hiện tại trước khi tạo phiên mới.',
                    409,
                    ['active_session_id' => (string) $activeRow['id']]
                );
            }

            try {
                $defaultTemplate = $this->catalog->resolve($this->config->defaultTemplate, null, true);
            } catch (ApiException $e) {
                $available = $this->catalog->templates('postcard-three');
                $available = $available !== [] ? $available : $this->catalog->templates();
                if ($available === []) {
                    throw $e;
                }
                $defaultTemplate = $available[0];
            }
            $defaultLayout = $this->catalog->layout($defaultTemplate['layout_id'], $defaultTemplate['layout_version']);
            try {
                $defaultLut = $this->luts->resolve($this->config->defaultLut, null, true);
            } catch (ApiException) {
                $defaultLut = $this->luts->resolve('original', '1.0.0');
            }
            $sessionId = Uuid::v4();
            $nowIso = Clock::nowIso();

            $defaultBeautyPreset = isset(self::BEAUTY_PRESETS[$this->config->defaultBeautyPreset]) ? $this->config->defaultBeautyPreset : 'natural';
            $defaultBeauty = self::BEAUTY_PRESETS[$defaultBeautyPreset];

            $pdo->prepare(
                'INSERT INTO sessions
                 (id, state, pre_job_state, revision, selected_lut, template_id, created_by, created_at, updated_at, layout_id, layout_version, template_version, capture_count, lut_version, lut_intensity, lut_sha256, beauty_preset, beauty_smooth, beauty_brighten, beauty_tone)
                 VALUES (:id, :state, NULL, 1, :lut, :tpl, :uid, :now, :now, :layout, :lv, :tv, :count, :lutv, :luti, :luts, :bpreset, :bsmooth, :bbrighten, :btone)'
            )->execute([
                ':id' => $sessionId,
                ':state' => 'NEW',
                ':lut' => $defaultLut['id'],
                ':lutv' => $defaultLut['version'],
                ':luti' => $defaultLut['intensity'],
                ':luts' => $defaultLut['sha256'],
                ':tpl' => $defaultTemplate['id'],
                ':layout' => $defaultLayout['id'],
                ':lv' => $defaultLayout['version'],
                ':tv' => $defaultTemplate['version'],
                ':count' => $defaultLayout['capture_count'],
                ':uid' => $actorId,
                ':now' => $nowIso,
                ':bpreset' => $defaultBeautyPreset,
                ':bsmooth' => $defaultBeauty['smooth'],
                ':bbrighten' => $defaultBeauty['brighten'],
                ':btone' => $defaultBeauty['tone'],
            ]);

            $slotIns = $pdo->prepare(
                'INSERT INTO session_slots (session_id, slot, current_photo_id, updated_at)
                 VALUES (:sid, :slot, NULL, :now)'
            );
            foreach (range(1, $defaultLayout['capture_count']) as $slotNum) {
                $slotIns->execute([
                    ':sid' => $sessionId,
                    ':slot' => $slotNum,
                    ':now' => $nowIso,
                ]);
            }

            $sessionData = $this->buildSessionView($pdo, $sessionId);

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.create',
                $idemKey,
                $sessionId,
                $payloadHash,
                $sessionId,
                201,
                $sessionData
            );

            AuditLogger::record($pdo, 'session.created', 'SUCCEEDED', $actorId, $sessionId, null, [
                'revision' => 1,
                'state' => 'NEW',
            ]);

            $this->logger->info('session_created', 'OK', ['session_id' => $sessionId]);

            return ['status_code' => 201, 'body' => $sessionData];
        });
    }

    private function pinnedLut(array $session): array
    {
        $item = $this->luts->resolve($session['selected_lut'], $session['lut_version']);
        if ($item['sha256'] !== $session['lut_sha256'] || $item['intensity'] !== (int) $session['lut_intensity']) {
            throw new ApiException('INTEGRITY_ERROR', 'Filter không khớp cấu hình phiên đã ghim.', 409);
        }
        return $item;
    }

    public function configure(string $sessionId, string $actorId, int $revision, array $payload, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        foreach (['layout_id','lut_id','lut_version','template_id','template_version','beauty_preset'] as $field) {
            if (isset($payload[$field]) && !is_string($payload[$field])) {
                throw new ApiException('INVALID_INPUT', 'Trường cấu hình phải là chuỗi: ' . $field, 422);
            }
        }
        if (isset($payload['ui_step']) && !is_int($payload['ui_step'])) {
            throw new ApiException('INVALID_INPUT', 'ui_step phải là số nguyên.', 422);
        }
        unset($payload['revision']);
        $hash = IdempotencyService::hashPayload(['session_id' => $sessionId, 'configuration' => $payload]);
        return $this->db->transaction(function (PDO $pdo) use ($sessionId, $actorId, $revision, $payload, $key, $hash): array {
            $replay = IdempotencyService::checkReplay($pdo, $actorId, 'session.configuration', $key, $hash);
            if ($replay !== null) {
                return ['status_code' => 200, 'body' => $replay['response']];
            }
            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $revision);
            $this->assertNoActiveContentJob($session);
            if ((int) $session['configuration_locked'] === 1) {
                throw new ApiException('CONFIGURATION_LOCKED', 'Cấu hình đã khóa. Hãy bắt đầu phiên mới để thay đổi.', 409);
            }
            if ($session['state'] !== 'NEW') {
                throw new ApiException('INVALID_STATE', 'Chỉ được cấu hình phiên chưa bắt đầu chụp.', 422);
            }
            $layoutId = (string) ($payload['layout_id'] ?? $session['layout_id']);
            $layout = $this->catalog->layout($layoutId);
            $lut = (string) ($payload['lut_id'] ?? $session['selected_lut']);
            if ($lut === $session['selected_lut'] && (!isset($payload['lut_version']) || $payload['lut_version'] === $session['lut_version'])) {
                $lutInfo = $this->luts->resolve($lut, $session['lut_version']);
            } else {
                $lutInfo = $this->luts->resolve($lut, $payload['lut_version'] ?? null, true);
            }
            if (isset($payload['lut_intensity']) && $payload['lut_intensity'] !== $lutInfo['intensity']) {
                throw new ApiException('INVALID_INPUT', 'Cường độ filter do Admin quản lý.', 422);
            }
            $fallback = false;
            if (isset($payload['template_id'])) {
                if ($payload['template_id'] === $session['template_id'] && (!isset($payload['template_version']) || $payload['template_version'] === $session['template_version'])) {
                    $template = $this->catalog->resolve($session['template_id'], $session['template_version']);
                } else {
                    $template = $this->catalog->resolve($payload['template_id'], $payload['template_version'] ?? null, true);
                }
            } elseif ($layoutId !== $session['layout_id']) {
                $previous = $this->catalog->resolve($session['template_id'], $session['template_version']);
                $choices = $this->catalog->templates($layoutId);
                $template = null;
                foreach ($choices as $candidate) {
                    if ($candidate['style'] === $previous['style']) {
                        $template = $candidate;
                        break;
                    }
                }
                if ($template === null) {
                    foreach ($choices as $candidate) {
                        if ($candidate['style'] === 'minimal') {
                            $template = $candidate;
                            break;
                        }
                    }
                    $template = $template ?? ($choices[0] ?? null);
                    $fallback = true;
                }
                if ($template === null) {
                    throw new ApiException('INVALID_TEMPLATE', 'Bố cục chưa có template đang hoạt động.', 422);
                }
            } else {
                $template = $this->catalog->resolve($session['template_id'], $session['template_version']);
            }
            if ($template['layout_id'] !== $layoutId) {
                throw new ApiException('TEMPLATE_LAYOUT_MISMATCH', 'Template không khớp bố cục.', 422);
            }
            $step = (int) ($payload['ui_step'] ?? $session['ui_step']);
            if ($step < 1 || $step > 4) {
                throw new ApiException('INVALID_INPUT', 'Bước cấu hình không hợp lệ.', 422);
            }
            $beautyPreset = (string) ($payload['beauty_preset'] ?? $session['beauty_preset'] ?? 'natural');
            if (!array_key_exists($beautyPreset, self::BEAUTY_PRESETS)) {
                throw new ApiException('INVALID_INPUT', 'Preset làm đẹp không hợp lệ: ' . $beautyPreset, 422);
            }
            $presetDefaults = self::BEAUTY_PRESETS[$beautyPreset];
            $beautySmooth = isset($payload['beauty_smooth']) ? (int) $payload['beauty_smooth'] : (int) ($session['beauty_smooth'] ?? $presetDefaults['smooth']);
            $beautyBrighten = isset($payload['beauty_brighten']) ? (int) $payload['beauty_brighten'] : (int) ($session['beauty_brighten'] ?? $presetDefaults['brighten']);
            $beautyTone = isset($payload['beauty_tone']) ? (int) $payload['beauty_tone'] : (int) ($session['beauty_tone'] ?? $presetDefaults['tone']);
            if (isset($payload['beauty_preset']) && !isset($payload['beauty_smooth']) && !isset($payload['beauty_brighten']) && !isset($payload['beauty_tone'])) {
                $beautySmooth = $presetDefaults['smooth'];
                $beautyBrighten = $presetDefaults['brighten'];
                $beautyTone = $presetDefaults['tone'];
            }
            $beautySmooth = max(0, min(100, $beautySmooth));
            $beautyBrighten = max(0, min(100, $beautyBrighten));
            $beautyTone = max(0, min(100, $beautyTone));

            $now = Clock::nowIso();
            $pdo->prepare('UPDATE sessions SET selected_lut=:lut,lut_version=:lutv,lut_intensity=:luti,lut_sha256=:luts,layout_id=:layout,layout_version=:lv,template_id=:template,template_version=:tv,capture_count=:count,ui_step=:step,beauty_preset=:bpreset,beauty_smooth=:bsmooth,beauty_brighten=:bbrighten,beauty_tone=:btone,revision=revision+1,updated_at=:now WHERE id=:id')
                ->execute([':lut'=>$lut, ':lutv'=>$lutInfo['version'], ':luti'=>$lutInfo['intensity'], ':luts'=>$lutInfo['sha256'], ':layout'=>$layoutId, ':lv'=>$layout['version'], ':template'=>$template['id'], ':tv'=>$template['version'], ':count'=>$layout['capture_count'], ':step'=>$step, ':bpreset'=>$beautyPreset, ':bsmooth'=>$beautySmooth, ':bbrighten'=>$beautyBrighten, ':btone'=>$beautyTone, ':now'=>$now, ':id'=>$sessionId]);
            if ($layoutId !== $session['layout_id']) {
                $pdo->prepare('DELETE FROM session_slots WHERE session_id=:id')->execute([':id'=>$sessionId]);
                $insert = $pdo->prepare('INSERT INTO session_slots (session_id,slot,current_photo_id,updated_at) VALUES (:id,:slot,NULL,:now)');
                foreach (range(1, $layout['capture_count']) as $slot) {
                    $insert->execute([':id'=>$sessionId, ':slot'=>$slot, ':now'=>$now]);
                }
            }
            $view = $this->buildSessionView($pdo, $sessionId);
            $view['template_fallback'] = $fallback;
            IdempotencyService::record($pdo, $actorId, 'session.configuration', $key, $sessionId, $hash, $sessionId, 200, $view);
            AuditLogger::record($pdo, 'session.configuration', 'SUCCEEDED', $actorId, $sessionId, null, ['layout'=>$layoutId, 'template'=>$template['id']]);
            return ['status_code'=>200, 'body'=>$view];
        });
    }

    public function restart(string $sessionId, string $actorId, int $revision, bool $confirm, string $key): array
    {
        $key = IdempotencyService::validateKey($key);
        $hash = IdempotencyService::hashPayload(['session_id'=>$sessionId, 'confirm'=>$confirm]);
        return $this->db->transaction(function (PDO $pdo) use ($sessionId, $actorId, $revision, $confirm, $key, $hash): array {
            $replay = IdempotencyService::checkReplay($pdo, $actorId, 'session.restart', $key, $hash);
            if ($replay !== null) {
                return ['status_code'=>201, 'body'=>$replay['response']];
            }
            if (!$confirm) {
                throw new ApiException('CONFIRMATION_REQUIRED', 'Cần xác nhận bắt đầu lại phiên.', 422);
            }
            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $revision);
            $this->assertNoActiveContentJob($session);
            $childKey = hash('sha256', $sessionId . ':' . $key);
            if ($session['state'] === 'FINALIZED') {
                $this->closeSession($sessionId, $actorId, $revision, 'close-' . $childKey);
            } elseif (in_array($session['state'], ['NEW','CAPTURED','REVIEW'], true)) {
                $this->cancelSession($sessionId, $actorId, $revision, 'cancel-' . $childKey);
            } else {
                throw new ApiException('INVALID_STATE', 'Phiên đã kết thúc. Hãy tạo phiên mới.', 422);
            }
            $created = $this->createSession($actorId, 'create-' . $childKey);
            IdempotencyService::record($pdo, $actorId, 'session.restart', $key, $sessionId, $hash, $created['body']['id'], 201, $created['body']);
            AuditLogger::record($pdo, 'session.restart', 'SUCCEEDED', $actorId, $sessionId, null, ['new_session_id'=>$created['body']['id']]);
            return $created;
        });
    }

    /**
     * GET /custom/api/sessions/active
     *
     * @return ?array<string, mixed>
     */
    public function getActiveSession(): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->query(
            "SELECT id FROM sessions WHERE state NOT IN ('CLOSED', 'CANCELLED') AND deleted_at IS NULL LIMIT 1"
        );
        $row = $stmt !== false ? $stmt->fetch() : false;
        if (!is_array($row)) {
            return null;
        }
        return $this->buildSessionView($pdo, (string) $row['id']);
    }

    /**
     * GET /custom/api/sessions/{id}
     *
     * @return array<string, mixed>
     */
    public function getSession(string $sessionId): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }
        return $this->buildSessionView($this->db->pdo(), $sessionId);
    }

    /**
     * GET /custom/api/sessions
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function listSessions(?string $cursor = null, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $pdo = $this->db->pdo();

        $params = [];
        $whereCursor = '';
        if ($cursor !== null && trim($cursor) !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded !== false && str_contains($decoded, '|')) {
                [$cursorTime, $cursorId] = explode('|', $decoded, 2);
                $whereCursor = ' AND (created_at < :ct OR (created_at = :ct AND id < :cid))';
                $params[':ct'] = $cursorTime;
                $params[':cid'] = $cursorId;
            }
        }

        $sql = 'SELECT id FROM sessions WHERE deleted_at IS NULL' . $whereCursor . ' ORDER BY created_at DESC, id DESC LIMIT ' . ($limit + 1);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        foreach ($rows as $r) {
            $items[] = $this->buildSessionView($pdo, (string) $r['id']);
        }

        $nextCursor = null;
        if ($hasMore && $items !== []) {
            $last = $items[count($items) - 1];
            $nextCursor = base64_encode($last['created_at'] . '|' . $last['id']);
        }

        return [
            'items' => $items,
            'next_cursor' => $nextCursor,
        ];
    }

    /**
     * POST /custom/api/sessions/{id}/slots/{slot}/capture or /retake
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function enqueueSlotCaptureOrRetake(
        string $sessionId,
        string $actorId,
        int $slot,
        string $mode,
        int $expectedRevision,
        string $idempotencyKey,
        ?array $browserFrame = null
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $opName = $mode === 'retake' ? 'session.slot.retake' : 'session.slot.capture';
        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $frameContent = null;
        if ($this->config->cameraAdapter === 'browser') {
            if (($browserFrame['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($browserFrame['content'] ?? null) || $browserFrame['content'] === '') {
                throw new ApiException('CAMERA_FRAME_REQUIRED', 'Chưa nhận được ảnh camera. Bật camera và chụp lại (JPEG tối đa 5 MiB).', 422);
            }
            $frameContent = $browserFrame['content'];
            if (strlen($frameContent) > BrowserCameraAdapter::MAX_UPLOAD_BYTES) {
                throw new ApiException('IMAGE_TOO_LARGE', 'Ảnh camera vượt quá 5 MiB.', 413);
            }
        } elseif ($browserFrame !== null) {
            throw new ApiException('INVALID_CAMERA_MODE', 'Chỉ được gửi ảnh khi CAMERA_ADAPTER=browser.', 422);
        }
        $hashData = [
            'session_id' => $sessionId,
            'slot' => $slot,
            'mode' => $mode,
        ];
        if ($frameContent !== null) {
            $hashData['frame_sha256'] = hash('sha256', $frameContent);
        }
        $payloadHash = IdempotencyService::hashPayload($hashData);

        // Replay check BEFORE slot/revision/state validation
        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            $opName,
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        if ($slot < 1 || $slot > 4) {
            throw new ApiException('INVALID_SLOT', 'Số thứ tự slot chụp phải là 1, 2 hoặc 3.', 422);
        }

        $this->assetStore->assertDiskSpaceAvailable();

        $stagedDir = null;
        try {
            return $this->db->transaction(function (PDO $pdo) use (
                $sessionId,
                $actorId,
                $slot,
                $mode,
                $opName,
                $expectedRevision,
                $idemKey,
                $payloadHash,
                $frameContent,
                &$stagedDir
            ): array {
                $replayTx = IdempotencyService::checkReplay($pdo, $actorId, $opName, $idemKey, $payloadHash);
                if ($replayTx !== null) {
                    return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
                }

                $session = $this->lockSessionRow($pdo, $sessionId);
                $this->assertRevisionMatches($session, $expectedRevision);
                $this->assertNoActiveContentJob($session);

                if ($slot > (int) $session['capture_count']) {
                    throw new ApiException('INVALID_SLOT', 'Slot nằm ngoài bố cục của phiên.', 422);
                }
                $state = (string) $session['state'];
                $slotStmt = $pdo->prepare('SELECT current_photo_id FROM session_slots WHERE session_id = :sid AND slot = :slot');
                $slotStmt->execute([':sid' => $sessionId, ':slot' => $slot]);
                $slotRow = $slotStmt->fetch();
                $currentPhotoId = is_array($slotRow) ? $slotRow['current_photo_id'] : null;

                if ($mode === 'capture') {
                    if (!in_array($state, ['NEW', 'CAPTURED'], true)) {
                        throw new ApiException(
                            'INVALID_STATE',
                            "Không thể chụp mới ở trạng thái '{$state}'.",
                            422
                        );
                    }
                    if ($currentPhotoId !== null) {
                        throw new ApiException(
                            'INVALID_STATE',
                            "Slot {$slot} đã có ảnh. Vui lòng dùng thao tác chụp lại (retake).",
                            422
                        );
                    }
                } else {
                    // retake
                    if (!in_array($state, ['CAPTURED', 'REVIEW'], true)) {
                        throw new ApiException(
                            'INVALID_STATE',
                            "Chỉ có thể chụp lại khi phiên ở trạng thái CAPTURED hoặc REVIEW (hiện tại: '{$state}').",
                            422
                        );
                    }
                    if ($currentPhotoId === null) {
                        throw new ApiException(
                            'INVALID_STATE',
                            "Slot {$slot} chưa có ảnh gốc để chụp lại. Vui lòng dùng thao tác chụp (capture).",
                            422
                        );
                    }
                }

                // Compute next version number for this (session_id, slot)
                $verStmt = $pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM photos WHERE session_id = :sid AND slot = :slot');
                $verStmt->execute([':sid' => $sessionId, ':slot' => $slot]);
                $nextVersion = ((int) $verStmt->fetchColumn()) + 1;

                $newRevision = ((int) $session['revision']) + 1;
                $jobId = Uuid::v4();
                $nowIso = Clock::nowIso();

                $jobPayload = [
                    'slot' => $slot,
                    'version' => $nextVersion,
                    'mode' => $mode,
                    'previous_photo_id' => $currentPhotoId,
                    'camera_mode' => $this->config->cameraAdapter,
                ];
                if ($frameContent !== null) {
                    $stagedDir = $this->assetStore->pendingJobDir($sessionId, $jobId);
                    if (file_put_contents($stagedDir . '/browser.jpg', $frameContent, LOCK_EX) !== strlen($frameContent)) {
                        throw new ApiException('STORAGE_ERROR', 'Không thể lưu ảnh camera để xử lý.', 500);
                    }
                    $jobPayload['frame_sha256'] = hash('sha256', $frameContent);
                }

                $pdo->prepare(
                    'INSERT INTO jobs
                     (id, session_id, type, status, phase, progress, accepted_revision, attempt, max_attempts, payload, created_at)
                     VALUES (:id, :sid, :type, :status, :phase, 0, :rev, 1, 1, :payload, :now)'
                )->execute([
                    ':id' => $jobId,
                    ':sid' => $sessionId,
                    ':type' => $mode,
                    ':status' => 'QUEUED',
                    ':phase' => 'queued',
                    ':rev' => $newRevision,
                    ':payload' => json_encode($jobPayload, JSON_UNESCAPED_SLASHES) ?: '{}',
                    ':now' => $nowIso,
                ]);

                $pdo->prepare(
                    'UPDATE sessions
                     SET state = :new_state, pre_job_state = :pre_state, revision = :rev, active_job_id = :jid, updated_at = :now, configuration_locked = 1, ui_step = 4
                     WHERE id = :id'
                )->execute([
                    ':new_state' => 'CAPTURING',
                    ':pre_state' => $state,
                    ':rev' => $newRevision,
                    ':jid' => $jobId,
                    ':now' => $nowIso,
                    ':id' => $sessionId,
                ]);

                $response = [
                    'session_id' => $sessionId,
                    'revision' => $newRevision,
                    'state' => 'CAPTURING',
                    'slot' => $slot,
                    'version' => $nextVersion,
                    'job_id' => $jobId,
                ];

                IdempotencyService::record(
                    $pdo,
                    $actorId,
                    $opName,
                    $idemKey,
                    $sessionId,
                    $payloadHash,
                    $jobId,
                    202,
                    $response
                );

                AuditLogger::record($pdo, 'slot.' . $mode . '_enqueued', 'QUEUED', $actorId, $sessionId, $jobId, [
                    'slot' => $slot,
                    'version' => $nextVersion,
                    'revision' => $newRevision,
                ]);

                return ['status_code' => 202, 'body' => $response];
            });
        } catch (\Throwable $e) {
            if ($stagedDir !== null) {
                $this->assetStore->removeDirectoryRecursive(dirname($stagedDir));
            }
            throw $e;
        }
    }

    /**
     * POST /custom/api/sessions/{id}/render
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function enqueueRender(
        string $sessionId,
        string $actorId,
        int $expectedRevision,
        string $lutId,
        string $templateId,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload([
            'session_id' => $sessionId,
            'lut_id' => $lutId,
            'template_id' => $templateId,
        ]);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.render',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        $this->catalog->resolve($templateId);
        $this->assetStore->assertDiskSpaceAvailable();

        return $this->db->transaction(function (PDO $pdo) use (
            $sessionId,
            $actorId,
            $expectedRevision,
            $lutId,
            $templateId,
            $idemKey,
            $payloadHash
        ): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.render', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);
            $this->assertNoActiveContentJob($session);

            if ($lutId !== $session['selected_lut'] || $templateId !== $session['template_id']) {
                throw new ApiException('CONFIGURATION_LOCKED', 'Không thể đổi filter hoặc template sau khi bắt đầu chụp.', 409);
            }
            $renderTemplate = $this->catalog->renderSnapshot($session['template_id'], $session['template_version']);
            $state = (string) $session['state'];
            if (!in_array($state, ['CAPTURED', 'REVIEW'], true)) {
                if ($state === 'NEW') {
                    throw new ApiException(
                        'MISSING_PHOTOS',
                        'Chưa đủ ảnh theo bố cục để ghép khung.',
                        422,
                        ['missing_slots' => range(1, (int) $session['capture_count'])]
                    );
                }
                throw new ApiException(
                    'INVALID_STATE',
                    "Không thể render ở trạng thái '{$state}'.",
                    422
                );
            }

            // Verify every slot required by the pinned layout has a photo.
            $slotsStmt = $pdo->prepare(
                'SELECT ss.slot, ss.current_photo_id, p.path, p.sha256, p.version
                 FROM session_slots ss
                 LEFT JOIN photos p ON p.id = ss.current_photo_id
                 WHERE ss.session_id = :sid
                 ORDER BY ss.slot ASC'
            );
            $slotsStmt->execute([':sid' => $sessionId]);
            $slotRows = $slotsStmt->fetchAll();

            $missingSlots = [];
            $snapshotPhotos = [];
            foreach ($slotRows as $sr) {
                $sNum = (int) $sr['slot'];
                if ($sr['current_photo_id'] === null || $sr['path'] === null) {
                    $missingSlots[] = $sNum;
                } else {
                    $snapshotPhotos[] = [
                        'slot' => $sNum,
                        'photo_id' => (string) $sr['current_photo_id'],
                        'version' => (int) $sr['version'],
                        'path' => (string) $sr['path'],
                        'sha256' => (string) $sr['sha256'],
                    ];
                }
            }

            if ($missingSlots !== [] || count($snapshotPhotos) !== (int) $session['capture_count']) {
                throw new ApiException(
                    'MISSING_PHOTOS',
                    'Cần chụp đủ các ô của bố cục trước khi ghép ảnh.',
                    422,
                    ['missing_slots' => $missingSlots]
                );
            }

            // Section 5.1 line 248: Changing LUT or starting a new render immediately invalidates any previous render
            $pdo->prepare('UPDATE renders SET valid = 0 WHERE session_id = :sid')
                ->execute([':sid' => $sessionId]);

            $presetInfo = $this->pinnedLut($session);
            $newRevision = ((int) $session['revision']) + 1;
            $jobId = Uuid::v4();
            $nowIso = Clock::nowIso();

            $jobPayload = [
                'lut_id' => $lutId,
                'lut_version' => (string) ($presetInfo['version'] ?? '1.0.0'),
                'lut_file' => $presetInfo['file'] ?? null,
                'lut_sha256' => $presetInfo['sha256'],
                'lut_snapshot' => ['id'=>$lutId, 'version'=>$session['lut_version'], 'sha256'=>$session['lut_sha256'], 'intensity'=>(int) $session['lut_intensity']],
                'beauty_preset' => (string) ($session['beauty_preset'] ?? 'natural'),
                'beauty_config' => [
                    'smooth' => (int) ($session['beauty_smooth'] ?? 20),
                    'brighten' => (int) ($session['beauty_brighten'] ?? 5),
                    'tone' => (int) ($session['beauty_tone'] ?? 10),
                ],
                'template_id' => $templateId,
                'template' => $renderTemplate,
                'photos' => $snapshotPhotos,
            ];

            // Render allows max_attempts = 2 (1 automatic retry for transient worker/process crash)
            $pdo->prepare(
                'INSERT INTO jobs
                 (id, session_id, type, status, phase, progress, accepted_revision, attempt, max_attempts, payload, created_at)
                 VALUES (:id, :sid, :type, :status, :phase, 0, :rev, 1, 2, :payload, :now)'
            )->execute([
                ':id' => $jobId,
                ':sid' => $sessionId,
                ':type' => 'render',
                ':status' => 'QUEUED',
                ':phase' => 'queued',
                ':rev' => $newRevision,
                ':payload' => json_encode($jobPayload, JSON_UNESCAPED_SLASHES) ?: '{}',
                ':now' => $nowIso,
            ]);

            $pdo->prepare(
                'UPDATE sessions
                 SET ui_step = 5, state = :new_state,
                     pre_job_state = :pre_state,
                     revision = :rev,
                     selected_lut = :lut,
                     template_id = :tpl,
                     current_render_id = NULL,
                     active_job_id = :jid,
                     updated_at = :now
                 WHERE id = :id'
            )->execute([
                ':new_state' => 'RENDERING',
                ':pre_state' => 'CAPTURED',
                ':rev' => $newRevision,
                ':lut' => $lutId,
                ':tpl' => $templateId,
                ':jid' => $jobId,
                ':now' => $nowIso,
                ':id' => $sessionId,
            ]);

            $response = [
                'session_id' => $sessionId,
                'revision' => $newRevision,
                'state' => 'RENDERING',
                'job_id' => $jobId,
                'lut_id' => $lutId,
                'template_id' => $templateId,
                'input_snapshot' => array_map(
                    static fn (array $p): array => [
                        'slot' => $p['slot'],
                        'photo_id' => $p['photo_id'],
                        'version' => $p['version'],
                        'sha256' => $p['sha256'],
                    ],
                    $snapshotPhotos
                ),
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.render',
                $idemKey,
                $sessionId,
                $payloadHash,
                $jobId,
                202,
                $response
            );

            AuditLogger::record($pdo, 'render.enqueued', 'QUEUED', $actorId, $sessionId, $jobId, [
                'lut_id' => $lutId,
                'template_id' => $templateId,
                'revision' => $newRevision,
            ]);

            return ['status_code' => 202, 'body' => $response];
        });
    }

    /**
     * POST /custom/api/sessions/{id}/finalize
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function finalizeSession(
        string $sessionId,
        string $actorId,
        int $expectedRevision,
        string $renderId,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload([
            'session_id' => $sessionId,
            'render_id' => $renderId,
        ]);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.finalize',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        if (!Uuid::isValid($renderId)) {
            throw new ApiException('INVALID_STATE', 'Mã bản render không hợp lệ.', 422);
        }

        $this->assetStore->assertDiskSpaceAvailable();

        return $this->db->transaction(function (PDO $pdo) use (
            $sessionId,
            $actorId,
            $expectedRevision,
            $renderId,
            $idemKey,
            $payloadHash
        ): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.finalize', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);
            $this->assertNoActiveContentJob($session);

            if ((string) $session['state'] !== 'REVIEW') {
                throw new ApiException(
                    'INVALID_STATE',
                    'Chỉ có thể duyệt khóa ảnh cuối khi phiên đang ở trạng thái REVIEW.',
                    422
                );
            }

            if ((string) ($session['current_render_id'] ?? '') !== $renderId) {
                throw new ApiException(
                    'INVALID_STATE',
                    'Bản render được chọn không phải là bản render hiện hành hợp lệ của phiên.',
                    422
                );
            }

            $rStmt = $pdo->prepare('SELECT * FROM renders WHERE id = :rid AND session_id = :sid');
            $rStmt->execute([':rid' => $renderId, ':sid' => $sessionId]);
            $renderRow = $rStmt->fetch();

            if (!is_array($renderRow) || (int) $renderRow['valid'] !== 1) {
                throw new ApiException(
                    'INVALID_STATE',
                    'Bản render này đã hết hiệu lực do thay đổi ảnh hoặc bộ lọc màu.',
                    422
                );
            }

            if ($renderRow['template_id'] !== $session['template_id'] || $renderRow['template_version'] !== $session['template_version']) {
                throw new ApiException('INVALID_STATE', 'Template render không khớp cấu hình đã khóa.', 422);
            }

            if ((string) $renderRow['lut_id'] !== (string) $session['selected_lut'] || $renderRow['lut_version'] !== $session['lut_version']) {
                throw new ApiException(
                    'INVALID_STATE',
                    'Bộ lọc của bản render không khớp với lựa chọn hiện tại của phiên.',
                    422
                );
            }

            $snapshotStmt = $pdo->prepare('SELECT payload FROM jobs WHERE id = :id');
            $snapshotStmt->execute([':id' => $renderRow['job_id']]);
            $renderPayload = json_decode((string) $snapshotStmt->fetchColumn(), true) ?: [];
            $pinnedLut = $this->pinnedLut($session);
            if (isset($renderPayload['lut_snapshot']) && $renderPayload['lut_snapshot'] != ['id'=>$pinnedLut['id'], 'version'=>$pinnedLut['version'], 'sha256'=>$pinnedLut['sha256'], 'intensity'=>$pinnedLut['intensity']]) {
                throw new ApiException('INVALID_STATE', 'Filter hoặc cường độ không khớp snapshot render.', 422);
            }
            if (isset($renderPayload['template']) && $renderPayload['template'] != $this->catalog->renderSnapshot($session['template_id'], $session['template_version'])) {
                throw new ApiException('INVALID_STATE', 'Tài nguyên bố cục không khớp snapshot render.', 422);
            }

            // Verify the render snapshot matches every current photo of this layout.
            $slotIdsStmt = $pdo->prepare(
                'SELECT current_photo_id FROM session_slots WHERE session_id = :sid ORDER BY slot ASC'
            );
            $slotIdsStmt->execute([':sid' => $sessionId]);
            $currentSlotPhotoIds = array_map(
                static fn (array $r): string => (string) ($r['current_photo_id'] ?? ''),
                $slotIdsStmt->fetchAll()
            );
            $renderSourcePhotoIds = json_decode((string) $renderRow['source_photo_ids'], true);
            if (!is_array($renderSourcePhotoIds) || $renderSourcePhotoIds !== $currentSlotPhotoIds) {
                throw new ApiException(
                    'INVALID_STATE',
                    'Ảnh gốc của các slot đã thay đổi so với bản render.',
                    422
                );
            }

            $finalAssetId = Uuid::v4();
            $finalRelPath = $this->assetStore->finalizedAssetRelativePath($sessionId, $finalAssetId);
            $expectedSha = (string) $renderRow['sha256'];

            // Byte-for-byte atomic copy from print.jpg without re-encoding
            $publishedSha = $this->assetStore->publishFinalizedFromRender(
                (string) $renderRow['image_path'],
                $expectedSha,
                $finalRelPath
            );

            $finalAbsPath = $this->assetStore->absolutePath($finalRelPath);
            $finalBytes = (int) filesize($finalAbsPath);
            $nowIso = Clock::nowIso();
            $newRevision = ((int) $session['revision']) + 1;

            $pdo->prepare(
                'INSERT INTO final_assets (id, session_id, render_id, path, sha256, width, height, bytes, created_at)
                 VALUES (:id, :sid, :rid, :path, :sha, :w, :h, :bytes, :now)'
            )->execute([
                ':id' => $finalAssetId,
                ':sid' => $sessionId,
                ':rid' => $renderId,
                ':path' => $finalRelPath,
                ':sha' => $publishedSha,
                ':w' => (int) $renderRow['width'],
                ':h' => (int) $renderRow['height'],
                ':bytes' => $finalBytes,
                ':now' => $nowIso,
            ]);

            $pdo->prepare(
                'UPDATE sessions
                 SET state = :state, revision = :rev, current_final_id = :fid, updated_at = :now
                 WHERE id = :id'
            )->execute([
                ':state' => 'FINALIZED',
                ':rev' => $newRevision,
                ':fid' => $finalAssetId,
                ':now' => $nowIso,
                ':id' => $sessionId,
            ]);

            $response = [
                'session_id' => $sessionId,
                'revision' => $newRevision,
                'state' => 'FINALIZED',
                'final_asset_id' => $finalAssetId,
                'render_id' => $renderId,
                'sha256' => $publishedSha,
                'width' => (int) $renderRow['width'],
                'height' => (int) $renderRow['height'],
                'bytes' => $finalBytes,
                'asset_url' => '/custom/api/assets/' . $finalAssetId . '?variant=final',
                'created_at' => $nowIso,
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.finalize',
                $idemKey,
                $sessionId,
                $payloadHash,
                $finalAssetId,
                201,
                $response
            );

            AuditLogger::record($pdo, 'session.finalized', 'SUCCEEDED', $actorId, $sessionId, null, [
                'final_asset_id' => $finalAssetId,
                'render_id' => $renderId,
                'sha256' => $publishedSha,
                'revision' => $newRevision,
            ]);

            $this->logger->info('session_finalized', 'OK', [
                'session_id' => $sessionId,
                'final_asset_id' => $finalAssetId,
            ]);

            return ['status_code' => 201, 'body' => $response];
        });
    }

    /**
     * POST /custom/api/sessions/{id}/reopen
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function reopenSession(
        string $sessionId,
        string $actorId,
        int $expectedRevision,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload(['session_id' => $sessionId, 'op' => 'reopen']);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.reopen',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        return $this->db->transaction(function (PDO $pdo) use ($sessionId, $actorId, $expectedRevision, $idemKey, $payloadHash): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.reopen', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);
            $this->assertNoActiveContentJob($session);

            if ((string) $session['state'] !== 'FINALIZED') {
                throw new ApiException(
                    'INVALID_STATE',
                    'Chỉ có thể mở lại chỉnh sửa từ trạng thái FINALIZED.',
                    422
                );
            }

            // Section 5.1 line 250: Cannot reopen if there are unresolved print requests
            $unresolvedStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM print_requests
                 WHERE session_id = :sid AND status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING', 'UNKNOWN')"
            );
            $unresolvedStmt->execute([':sid' => $sessionId]);
            if ((int) $unresolvedStmt->fetchColumn() > 0) {
                throw new ApiException(
                    'INVALID_STATE',
                    'Không thể mở lại phiên khi còn yêu cầu in đang xử lý hoặc ở trạng thái UNKNOWN chưa giải quyết.',
                    422
                );
            }

            $pdo->prepare('UPDATE renders SET valid = 0 WHERE session_id = :sid')
                ->execute([':sid' => $sessionId]);

            $newRevision = ((int) $session['revision']) + 1;
            $nowIso = Clock::nowIso();

            $pdo->prepare(
                'UPDATE sessions
                 SET ui_step = 4, state = :st, revision = :rev, current_render_id = NULL, current_final_id = NULL, updated_at = :now
                 WHERE id = :id'
            )->execute([
                ':st' => 'CAPTURED',
                ':rev' => $newRevision,
                ':now' => $nowIso,
                ':id' => $sessionId,
            ]);

            $response = [
                'session_id' => $sessionId,
                'revision' => $newRevision,
                'state' => 'CAPTURED',
                'updated_at' => $nowIso,
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.reopen',
                $idemKey,
                $sessionId,
                $payloadHash,
                $sessionId,
                200,
                $response
            );

            AuditLogger::record($pdo, 'session.reopened', 'SUCCEEDED', $actorId, $sessionId, null, [
                'revision' => $newRevision,
            ]);

            return ['status_code' => 200, 'body' => $response];
        });
    }

    /**
     * POST /custom/api/sessions/{id}/close
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function closeSession(
        string $sessionId,
        string $actorId,
        int $expectedRevision,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload(['session_id' => $sessionId, 'op' => 'close']);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.close',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        return $this->db->transaction(function (PDO $pdo) use ($sessionId, $actorId, $expectedRevision, $idemKey, $payloadHash): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.close', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);
            $this->assertNoActiveContentJob($session);

            if ((string) $session['state'] !== 'FINALIZED') {
                throw new ApiException(
                    'INVALID_STATE',
                    'Chỉ có thể đóng phiên khi đã duyệt khóa ảnh cuối (FINALIZED).',
                    422
                );
            }

            $nowUnix = Clock::nowUnix();
            $nowIso = Clock::fromUnixIso($nowUnix);
            $retentionDeadlineIso = Clock::fromUnixIso($nowUnix + ($this->config->sessionRetentionDays * 86400));
            $newRevision = ((int) $session['revision']) + 1;

            $pdo->prepare(
                'UPDATE sessions
                 SET state = :st, revision = :rev, closed_at = :now, expires_at = :exp, updated_at = :now
                 WHERE id = :id'
            )->execute([
                ':st' => 'CLOSED',
                ':rev' => $newRevision,
                ':now' => $nowIso,
                ':exp' => $retentionDeadlineIso,
                ':id' => $sessionId,
            ]);

            // Section 9.4 line 508: Shorten any active share token whose expires_at exceeds retentionDeadline
            $pdo->prepare(
                'UPDATE share_tokens
                 SET expires_at = :cap
                 WHERE session_id = :sid AND revoked_at IS NULL AND expires_at > :cap'
            )->execute([
                ':cap' => $retentionDeadlineIso,
                ':sid' => $sessionId,
            ]);

            $response = [
                'session_id' => $sessionId,
                'revision' => $newRevision,
                'state' => 'CLOSED',
                'closed_at' => $nowIso,
                'retention_deadline' => $retentionDeadlineIso,
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.close',
                $idemKey,
                $sessionId,
                $payloadHash,
                $sessionId,
                200,
                $response
            );

            AuditLogger::record($pdo, 'session.closed', 'SUCCEEDED', $actorId, $sessionId, null, [
                'revision' => $newRevision,
                'retention_deadline' => $retentionDeadlineIso,
            ]);

            return ['status_code' => 200, 'body' => $response];
        });
    }

    /**
     * POST /custom/api/sessions/{id}/cancel
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function cancelSession(
        string $sessionId,
        string $actorId,
        int $expectedRevision,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload(['session_id' => $sessionId, 'op' => 'cancel']);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.cancel',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        return $this->db->transaction(function (PDO $pdo) use ($sessionId, $actorId, $expectedRevision, $idemKey, $payloadHash): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.cancel', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);
            $this->assertNoActiveContentJob($session);

            $state = (string) $session['state'];
            if (!in_array($state, ['NEW', 'CAPTURED', 'REVIEW'], true)) {
                throw new ApiException(
                    'INVALID_STATE',
                    "Chỉ có thể hủy phiên từ trạng thái NEW, CAPTURED hoặc REVIEW (hiện tại: '{$state}').",
                    422
                );
            }

            $nowUnix = Clock::nowUnix();
            $nowIso = Clock::fromUnixIso($nowUnix);
            $retentionDeadlineIso = Clock::fromUnixIso($nowUnix + ($this->config->sessionRetentionDays * 86400));
            $newRevision = ((int) $session['revision']) + 1;

            $pdo->prepare(
                'UPDATE sessions
                 SET state = :st, revision = :rev, current_render_id = NULL, closed_at = :now, expires_at = :exp, updated_at = :now
                 WHERE id = :id'
            )->execute([
                ':st' => 'CANCELLED',
                ':rev' => $newRevision,
                ':now' => $nowIso,
                ':exp' => $retentionDeadlineIso,
                ':id' => $sessionId,
            ]);

            $response = [
                'session_id' => $sessionId,
                'revision' => $newRevision,
                'state' => 'CANCELLED',
                'closed_at' => $nowIso,
                'retention_deadline' => $retentionDeadlineIso,
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.cancel',
                $idemKey,
                $sessionId,
                $payloadHash,
                $sessionId,
                200,
                $response
            );

            AuditLogger::record($pdo, 'session.cancelled', 'SUCCEEDED', $actorId, $sessionId, null, [
                'revision' => $newRevision,
                'retention_deadline' => $retentionDeadlineIso,
            ]);

            return ['status_code' => 200, 'body' => $response];
        });
    }

    /**
     * POST /custom/api/sessions/{id}/shares
     * Issues a LAN QR share token without storing plaintext token in share_tokens or idempotency_records.
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function createShareToken(
        string $sessionId,
        string $actorId,
        int $expectedRevision,
        string $finalAssetId,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload([
            'session_id' => $sessionId,
            'final_asset_id' => $finalAssetId,
        ]);

        // Reconstruct token deterministically on replay without reading plaintext from DB
        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'session.share',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return [
                'status_code' => $replay['status_code'],
                'body' => $this->hydrateShareResponse($replay['response']),
            ];
        }

        if (!Uuid::isValid($finalAssetId)) {
            throw new ApiException('INVALID_STATE', 'Mã ảnh cuối (final_asset_id) không hợp lệ.', 422);
        }

        AppConfig::validateShareBaseUrl($this->config->shareBaseUrl);

        $txResult = $this->db->transaction(function (PDO $pdo) use (
            $sessionId,
            $actorId,
            $expectedRevision,
            $finalAssetId,
            $idemKey,
            $payloadHash
        ): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'session.share', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return $replayTx;
            }

            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);

            $state = (string) $session['state'];
            if (!in_array($state, ['FINALIZED', 'CLOSED'], true)) {
                throw new ApiException(
                    'INVALID_STATE',
                    'Chỉ có thể tạo mã QR chia sẻ khi phiên ở trạng thái FINALIZED hoặc CLOSED.',
                    422
                );
            }

            $faStmt = $pdo->prepare('SELECT * FROM final_assets WHERE id = :fid AND session_id = :sid');
            $faStmt->execute([':fid' => $finalAssetId, ':sid' => $sessionId]);
            $finalAsset = $faStmt->fetch();
            if (!is_array($finalAsset)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy ảnh cuối thuộc phiên này.', 404);
            }

            $this->assetStore->verifyAssetIntegrity(
                (string) $finalAsset['path'],
                (string) $finalAsset['sha256'],
                $pdo,
                $sessionId
            );

            $nowUnix = Clock::nowUnix();
            $nowIso = Clock::fromUnixIso($nowUnix);
            $ttlExpiresUnix = $nowUnix + ($this->config->shareTtlHours * 3600);

            if ($session['expires_at'] !== null) {
                $sessionExpiresUnix = Clock::toUnix((string) $session['expires_at']);
                if ($sessionExpiresUnix > 0 && $sessionExpiresUnix < $ttlExpiresUnix) {
                    $ttlExpiresUnix = $sessionExpiresUnix;
                }
            }
            $expiresIso = Clock::fromUnixIso($ttlExpiresUnix);

            $shareId = Uuid::v4();
            $signedToken = $this->shareService->buildSignedToken($shareId, $finalAssetId);
            $tokenHash = hash('sha256', $signedToken);
            $newRevision = ((int) $session['revision']) + 1;

            $pdo->prepare('UPDATE sessions SET revision = :rev, updated_at = :now WHERE id = :id')
                ->execute([':rev' => $newRevision, ':now' => $nowIso, ':id' => $sessionId]);

            $pdo->prepare(
                'INSERT INTO share_tokens (id, session_id, final_asset_id, token_hash, created_by, created_at, expires_at, revoked_at)
                 VALUES (:id, :sid, :fid, :th, :uid, :now, :exp, NULL)'
            )->execute([
                ':id' => $shareId,
                ':sid' => $sessionId,
                ':fid' => $finalAssetId,
                ':th' => $tokenHash,
                ':uid' => $actorId,
                ':now' => $nowIso,
                ':exp' => $expiresIso,
            ]);

            // Store metadata ONLY (no plaintext token or URL containing token) in idempotency_records
            $storedResponse = [
                'share_id' => $shareId,
                'session_id' => $sessionId,
                'final_asset_id' => $finalAssetId,
                'revision' => $newRevision,
                'expires_at' => $expiresIso,
                'created_at' => $nowIso,
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'session.share',
                $idemKey,
                $sessionId,
                $payloadHash,
                $shareId,
                201,
                $storedResponse
            );

            AuditLogger::record($pdo, 'share.created', 'SUCCEEDED', $actorId, $sessionId, null, [
                'share_id' => $shareId,
                'final_asset_id' => $finalAssetId,
                'expires_at' => $expiresIso,
                'revision' => $newRevision,
            ]);

            return [
                'status_code' => 201,
                'resource_id' => $shareId,
                'response' => $storedResponse,
            ];
        });

        return [
            'status_code' => $txResult['status_code'],
            'body' => $this->hydrateShareResponse($txResult['response']),
        ];
    }

    /**
     * POST /custom/api/shares/{id}/revoke
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function revokeShareToken(
        string $shareId,
        string $actorId,
        int $expectedRevision,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($shareId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy mã chia sẻ.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload(['share_id' => $shareId, 'op' => 'revoke']);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $actorId,
            'share.revoke',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        return $this->db->transaction(function (PDO $pdo) use ($shareId, $actorId, $expectedRevision, $idemKey, $payloadHash): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $actorId, 'share.revoke', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $stStmt = $pdo->prepare('SELECT * FROM share_tokens WHERE id = :id');
            $stStmt->execute([':id' => $shareId]);
            $shareRow = $stStmt->fetch();
            if (!is_array($shareRow)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy mã chia sẻ.', 404);
            }

            $sessionId = (string) $shareRow['session_id'];
            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);
            $this->assertNoActiveContentJob($session);

            $nowIso = Clock::nowIso();
            $newRevision = ((int) $session['revision']) + 1;

            $pdo->prepare('UPDATE sessions SET revision = :rev, updated_at = :now WHERE id = :id')
                ->execute([':rev' => $newRevision, ':now' => $nowIso, ':id' => $sessionId]);

            $pdo->prepare('UPDATE share_tokens SET revoked_at = COALESCE(revoked_at, :now) WHERE id = :id')
                ->execute([':now' => $nowIso, ':id' => $shareId]);

            $response = [
                'share_id' => $shareId,
                'session_id' => $sessionId,
                'revoked_at' => $nowIso,
                'revision' => $newRevision,
            ];

            IdempotencyService::record(
                $pdo,
                $actorId,
                'share.revoke',
                $idemKey,
                $sessionId,
                $payloadHash,
                $shareId,
                200,
                $response
            );

            AuditLogger::record($pdo, 'share.revoked', 'SUCCEEDED', $actorId, $sessionId, null, [
                'share_id' => $shareId,
                'revision' => $newRevision,
            ]);

            return ['status_code' => 200, 'body' => $response];
        });
    }

    /**
     * POST /custom/api/sessions/{id}/delete (Admin only, two-phase delete)
     * Phase 1: Marks tombstone (deleted_at), immediately revokes all share tokens,
     * and enqueues delete_session job for the worker to purge files and metadata.
     *
     * @return array{status_code: int, body: array<string, mixed>}
     */
    public function requestDeleteSession(
        string $sessionId,
        string $adminUserId,
        int $expectedRevision,
        bool $confirmed,
        string $idempotencyKey
    ): array
    {
        if (!Uuid::isValid($sessionId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        $idemKey = IdempotencyService::validateKey($idempotencyKey);
        $payloadHash = IdempotencyService::hashPayload([
            'session_id' => $sessionId,
            'confirm' => $confirmed,
        ]);

        $replay = IdempotencyService::checkReplay(
            $this->db->pdo(),
            $adminUserId,
            'session.delete',
            $idemKey,
            $payloadHash
        );
        if ($replay !== null) {
            return ['status_code' => $replay['status_code'], 'body' => $replay['response']];
        }

        if (!$confirmed) {
            throw new ApiException('INVALID_INPUT', 'Cần xác nhận (confirm=true) để xóa vĩnh viễn phiên chụp.', 422);
        }

        return $this->db->transaction(function (PDO $pdo) use ($sessionId, $adminUserId, $expectedRevision, $idemKey, $payloadHash): array {
            $replayTx = IdempotencyService::checkReplay($pdo, $adminUserId, 'session.delete', $idemKey, $payloadHash);
            if ($replayTx !== null) {
                return ['status_code' => $replayTx['status_code'], 'body' => $replayTx['response']];
            }

            $session = $this->lockSessionRow($pdo, $sessionId);
            $this->assertRevisionMatches($session, $expectedRevision);
            $this->assertNoActiveContentJob($session);

            // Section 9.4 line 511: Cannot delete while unresolved print requests exist
            $unresolvedStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM print_requests
                 WHERE session_id = :sid AND status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING', 'UNKNOWN')"
            );
            $unresolvedStmt->execute([':sid' => $sessionId]);
            if ((int) $unresolvedStmt->fetchColumn() > 0) {
                throw new ApiException(
                    'SESSION_BUSY',
                    'Không thể xóa phiên khi còn yêu cầu in chưa hoàn tất hoặc đang ở trạng thái UNKNOWN.',
                    409
                );
            }

            $nowIso = Clock::nowIso();
            $newRevision = ((int) $session['revision']) + 1;
            $jobId = Uuid::v4();

            // Immediately revoke all share tokens for this session
            $pdo->prepare('UPDATE share_tokens SET revoked_at = COALESCE(revoked_at, :now) WHERE session_id = :sid')
                ->execute([':now' => $nowIso, ':sid' => $sessionId]);

            // Enqueue background file/metadata deletion job
            $pdo->prepare(
                'INSERT INTO jobs
                 (id, session_id, type, status, phase, progress, accepted_revision, attempt, max_attempts, payload, created_at)
                 VALUES (:id, :sid, :type, :status, :phase, 0, :rev, 1, 2, :payload, :now)'
            )->execute([
                ':id' => $jobId,
                ':sid' => $sessionId,
                ':type' => 'delete_session',
                ':status' => 'QUEUED',
                ':phase' => 'tombstoned',
                ':rev' => $newRevision,
                ':payload' => json_encode(['session_id' => $sessionId], JSON_UNESCAPED_SLASHES) ?: '{}',
                ':now' => $nowIso,
            ]);

            // Mark tombstone
            $pdo->prepare(
                'UPDATE sessions
                 SET deleted_at = :now, revision = :rev, active_job_id = :jid, updated_at = :now
                 WHERE id = :id'
            )->execute([
                ':now' => $nowIso,
                ':rev' => $newRevision,
                ':jid' => $jobId,
                ':id' => $sessionId,
            ]);

            $response = [
                'session_id' => $sessionId,
                'job_id' => $jobId,
                'revision' => $newRevision,
                'status' => 'DELETE_QUEUED',
                'deleted_at' => $nowIso,
            ];

            IdempotencyService::record(
                $pdo,
                $adminUserId,
                'session.delete',
                $idemKey,
                $sessionId,
                $payloadHash,
                $jobId,
                202,
                $response
            );

            AuditLogger::record($pdo, 'session.delete_requested', 'TOMBSTONED', $adminUserId, $sessionId, $jobId, [
                'revision' => $newRevision,
            ]);

            return ['status_code' => 202, 'body' => $response];
        });
    }

    /**
     * GET /custom/api/jobs/{id}
     *
     * @return array<string, mixed>
     */
    public function getJob(string $jobId): array
    {
        if (!Uuid::isValid($jobId)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy tác vụ.', 404);
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy tác vụ.', 404);
        }

        return [
            'id' => (string) $row['id'],
            'session_id' => (string) $row['session_id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'phase' => (string) $row['phase'],
            'progress' => (int) $row['progress'],
            'accepted_revision' => (int) $row['accepted_revision'],
            'attempt' => (int) $row['attempt'],
            'result' => $row['result'] !== null ? json_decode((string) $row['result'], true) : null,
            'error' => $row['error'] !== null ? json_decode((string) $row['error'], true) : null,
            'heartbeat_at' => $row['heartbeat_at'],
            'created_at' => (string) $row['created_at'],
            'started_at' => $row['started_at'],
            'completed_at' => $row['completed_at'],
        ];
    }

    /**
     * Resolve an authenticated operator asset request (`/custom/api/assets/{id}?variant=original|preview|print|final`).
     * Denies access if session is tombstoned (`deleted_at IS NOT NULL`) or path is tampered (`TC-17`).
     *
     * @return array{abs_path: string, sha256: string, mime: string}
     */
    public function resolveOperatorAsset(string $assetId, string $variant = 'original'): array
    {
        if (!Uuid::isValid($assetId)) {
            throw new ApiException('NOT_FOUND', 'Mã tài sản ảnh không hợp lệ.', 404);
        }
        if (!in_array($variant, ['original', 'preview', 'print', 'final'], true)) {
            throw new ApiException('INVALID_INPUT', 'Loại biến thể ảnh (variant) không được phép.', 422);
        }

        $pdo = $this->db->pdo();

        if ($variant === 'original') {
            $stmt = $pdo->prepare(
                'SELECT p.path, p.sha256, p.session_id
                 FROM photos p
                 JOIN sessions s ON s.id = p.session_id
                 WHERE p.id = :id AND s.deleted_at IS NULL'
            );
            $stmt->execute([':id' => $assetId]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy ảnh gốc.', 404);
            }
            $abs = $this->assetStore->verifyAssetIntegrity((string) $row['path'], (string) $row['sha256'], $pdo, (string) $row['session_id']);
            return ['abs_path' => $abs, 'sha256' => (string) $row['sha256'], 'mime' => 'image/jpeg'];
        }

        if ($variant === 'preview' || $variant === 'print') {
            $stmt = $pdo->prepare(
                'SELECT r.image_path, r.preview_path, r.sha256, r.preview_sha256, r.session_id, r.valid
                 FROM renders r
                 JOIN sessions s ON s.id = r.session_id
                 WHERE r.id = :id AND s.deleted_at IS NULL'
            );
            $stmt->execute([':id' => $assetId]);
            $row = $stmt->fetch();
            if (!is_array($row) || (int) $row['valid'] !== 1) {
                throw new ApiException('NOT_FOUND', 'Không tìm thấy bản render hợp lệ.', 404);
            }
            $relPath = $variant === 'preview' ? (string) $row['preview_path'] : (string) $row['image_path'];
            $sha = $variant === 'preview' ? (string) $row['preview_sha256'] : (string) $row['sha256'];
            $abs = $this->assetStore->verifyAssetIntegrity($relPath, $sha, $pdo, (string) $row['session_id']);
            return ['abs_path' => $abs, 'sha256' => $sha, 'mime' => 'image/jpeg'];
        }

        // variant === 'final'
        $stmt = $pdo->prepare(
            'SELECT fa.path, fa.sha256, fa.session_id
             FROM final_assets fa
             JOIN sessions s ON s.id = fa.session_id
             WHERE fa.id = :id AND s.deleted_at IS NULL'
        );
        $stmt->execute([':id' => $assetId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy ảnh cuối đã duyệt.', 404);
        }
        $abs = $this->assetStore->verifyAssetIntegrity((string) $row['path'], (string) $row['sha256'], $pdo, (string) $row['session_id']);
        return ['abs_path' => $abs, 'sha256' => (string) $row['sha256'], 'mime' => 'image/jpeg'];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSessionView(PDO $pdo, string $sessionId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch();
        if (!is_array($session)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }

        // Slots 1, 2, 3
        $slotStmt = $pdo->prepare(
            'SELECT ss.slot, ss.current_photo_id, ss.updated_at AS slot_updated_at,
                    p.version, p.sha256, p.width, p.height, p.bytes, p.created_at AS photo_created_at
             FROM session_slots ss
             LEFT JOIN photos p ON p.id = ss.current_photo_id
             WHERE ss.session_id = :sid
             ORDER BY ss.slot ASC'
        );
        $slotStmt->execute([':sid' => $sessionId]);
        $slots = [];
        $acceptedSlots = 0;

        foreach ($slotStmt->fetchAll() as $sr) {
            $hasPhoto = $sr['current_photo_id'] !== null;
            if ($hasPhoto) {
                $acceptedSlots++;
            }
            $slotNum = (int) $sr['slot'];
            $photoId = $hasPhoto ? (string) $sr['current_photo_id'] : null;
            $slots[] = [
                'slot' => $slotNum,
                'photo' => $hasPhoto ? [
                    'id' => $photoId,
                    'version' => (int) $sr['version'],
                    'sha256' => (string) $sr['sha256'],
                    'width' => (int) $sr['width'],
                    'height' => (int) $sr['height'],
                    'bytes' => (int) $sr['bytes'],
                    'url' => '/custom/api/assets/' . $photoId . '?variant=original',
                    'created_at' => (string) $sr['photo_created_at'],
                ] : null,
            ];
        }

        // Current render if valid
        $currentRender = null;
        if ($session['current_render_id'] !== null) {
            $rStmt = $pdo->prepare('SELECT * FROM renders WHERE id = :id AND valid = 1');
            $rStmt->execute([':id' => $session['current_render_id']]);
            $rRow = $rStmt->fetch();
            if (is_array($rRow)) {
                $rid = (string) $rRow['id'];
                $currentRender = [
                    'id' => $rid,
                    'lut_id' => (string) $rRow['lut_id'],
                    'lut_version' => (string) $rRow['lut_version'],
                    'template_id' => (string) $rRow['template_id'],
                    'input_signature' => (string) $rRow['input_signature'],
                    'sha256' => (string) $rRow['sha256'],
                    'preview_sha256' => (string) $rRow['preview_sha256'],
                    'width' => (int) $rRow['width'],
                    'height' => (int) $rRow['height'],
                    'duration_ms' => (int) $rRow['duration_ms'],
                    'preview_url' => '/custom/api/assets/' . $rid . '?variant=preview',
                    'print_url' => '/custom/api/assets/' . $rid . '?variant=print',
                    'created_at' => (string) $rRow['created_at'],
                ];
            }
        }

        // All final assets for this session (current and historical after reopen)
        $faStmt = $pdo->prepare('SELECT * FROM final_assets WHERE session_id = :sid ORDER BY created_at DESC');
        $faStmt->execute([':sid' => $sessionId]);
        $finals = [];
        $currentFinal = null;
        foreach ($faStmt->fetchAll() as $fRow) {
            $fid = (string) $fRow['id'];
            $item = [
                'id' => $fid,
                'render_id' => (string) $fRow['render_id'],
                'sha256' => (string) $fRow['sha256'],
                'width' => (int) $fRow['width'],
                'height' => (int) $fRow['height'],
                'bytes' => (int) $fRow['bytes'],
                'url' => '/custom/api/assets/' . $fid . '?variant=final',
                'created_at' => (string) $fRow['created_at'],
            ];
            $finals[] = $item;
            if ($session['current_final_id'] !== null && $fid === (string) $session['current_final_id']) {
                $currentFinal = $item;
            }
        }

        // Active job
        $activeJob = null;
        if ($session['active_job_id'] !== null) {
            $jStmt = $pdo->prepare('SELECT * FROM jobs WHERE id = :id');
            $jStmt->execute([':id' => $session['active_job_id']]);
            $jRow = $jStmt->fetch();
            if (is_array($jRow)) {
                $activeJob = [
                    'id' => (string) $jRow['id'],
                    'type' => (string) $jRow['type'],
                    'status' => (string) $jRow['status'],
                    'phase' => (string) $jRow['phase'],
                    'progress' => (int) $jRow['progress'],
                    'accepted_revision' => (int) $jRow['accepted_revision'],
                    'error' => $jRow['error'] !== null ? json_decode((string) $jRow['error'], true) : null,
                ];
            }
        }

        // Print summary
        $prStmt = $pdo->prepare('SELECT * FROM print_requests WHERE session_id = :sid ORDER BY created_at DESC');
        $prStmt->execute([':sid' => $sessionId]);
        $prints = [];
        $hasUnknownPrint = false;
        $hasActivePrint = false;
        foreach ($prStmt->fetchAll() as $pRow) {
            $st = (string) $pRow['status'];
            if ($st === 'UNKNOWN') {
                $hasUnknownPrint = true;
            }
            if (in_array($st, ['PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING'], true)) {
                $hasActivePrint = true;
            }
            $prints[] = [
                'id' => (string) $pRow['id'],
                'final_asset_id' => (string) $pRow['final_asset_id'],
                'copies' => (int) $pRow['copies'],
                'action' => (string) $pRow['action'],
                'status' => $st,
                'adapter_job_id' => $pRow['adapter_job_id'],
                'error_code' => $pRow['error_code'],
                'reason' => $pRow['error_message'],
                'resolution_outcome' => $pRow['resolution_outcome'],
                'resolution_note' => $pRow['resolution_note'],
                'created_at' => (string) $pRow['created_at'],
                'submitted_at' => $pRow['submitted_at'],
                'completed_at' => $pRow['completed_at'],
            ];
        }

        // Share tokens metadata (reconstructing QR URL safely for operator UI when token is active)
        $shStmt = $pdo->prepare('SELECT * FROM share_tokens WHERE session_id = :sid ORDER BY created_at DESC');
        $shStmt->execute([':sid' => $sessionId]);
        $shares = [];
        $nowUnix = Clock::nowUnix();
        foreach ($shStmt->fetchAll() as $shRow) {
            $shId = (string) $shRow['id'];
            $fId = (string) $shRow['final_asset_id'];
            $isRevoked = $shRow['revoked_at'] !== null;
            $isExpired = Clock::toUnix((string) $shRow['expires_at']) <= $nowUnix;
            $signed = $this->shareService->buildSignedToken($shId, $fId);
            $shares[] = [
                'id' => $shId,
                'final_asset_id' => $fId,
                'url' => (!$isRevoked && !$isExpired) ? $this->shareService->buildShareUrl($signed) : null,
                'expires_at' => (string) $shRow['expires_at'],
                'revoked_at' => $shRow['revoked_at'],
                'active' => !$isRevoked && !$isExpired,
                'created_at' => (string) $shRow['created_at'],
            ];
        }

        return [
            'id' => (string) $session['id'],
            'state' => (string) $session['state'],
            'revision' => (int) $session['revision'],
            'accepted_slots' => $acceptedSlots,
            'selected_lut' => (string) $session['selected_lut'],
            'lut_version' => (string) $session['lut_version'],
            'lut_intensity' => (int) $session['lut_intensity'],
            'lut_sha256' => $session['lut_sha256'],
            'lut' => $this->luts->describe($session['selected_lut'], $session['lut_version']),
            'template_id' => (string) $session['template_id'],
            'template_version' => (string) $session['template_version'],
            'template' => $this->catalog->describe($session['template_id'], $session['template_version']),
            'beauty' => [
                'preset' => (string) ($session['beauty_preset'] ?? 'natural'),
                'smooth' => (int) ($session['beauty_smooth'] ?? 20),
                'brighten' => (int) ($session['beauty_brighten'] ?? 5),
                'tone' => (int) ($session['beauty_tone'] ?? 10),
                'enabled' => ($session['beauty_preset'] ?? 'natural') !== 'off',
            ],
            'layout_id' => (string) $session['layout_id'],
            'layout_version' => (string) $session['layout_version'],
            'capture_count' => (int) $session['capture_count'],
            'configuration_locked' => (bool) $session['configuration_locked'],
            'ui_step' => in_array($session['state'], ['RENDERING','REVIEW','FINALIZED','CLOSED'], true) ? 5 : (int) $session['ui_step'],
            'slots' => $slots,
            'current_render' => $currentRender,
            'current_final' => $currentFinal,
            'final_assets' => $finals,
            'active_job' => $activeJob,
            'print_summary' => [
                'total_requests' => count($prints),
                'has_unknown' => $hasUnknownPrint,
                'has_active' => $hasActivePrint,
                'requests' => $prints,
            ],
            'shares' => $shares,
            'created_at' => (string) $session['created_at'],
            'updated_at' => (string) $session['updated_at'],
            'closed_at' => $session['closed_at'],
            'retention_deadline' => $session['expires_at'],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private function hydrateShareResponse(array $stored): array
    {
        $shareId = (string) $stored['share_id'];
        $finalAssetId = (string) $stored['final_asset_id'];
        $signedToken = $this->shareService->buildSignedToken($shareId, $finalAssetId);
        $url = $this->shareService->buildShareUrl($signedToken);
        $downloadUrl = $this->shareService->buildDownloadUrl($signedToken);

        return [
            'share_id' => $shareId,
            'session_id' => (string) $stored['session_id'],
            'final_asset_id' => $finalAssetId,
            'revision' => (int) $stored['revision'],
            'url' => $url,
            'download_url' => $downloadUrl,
            'qr_data_uri' => $this->shareService->generateOfflineQrDataUri($url),
            'expires_at' => (string) $stored['expires_at'],
            'created_at' => (string) $stored['created_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockSessionRow(PDO $pdo, string $sessionId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM sessions WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $sessionId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new ApiException('NOT_FOUND', 'Không tìm thấy phiên chụp.', 404);
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function assertRevisionMatches(array $session, int $expectedRevision): void
    {
        $currentRev = (int) $session['revision'];
        if ($currentRev !== $expectedRevision) {
            throw new ApiException(
                'REVISION_CONFLICT',
                'Phiên chụp đã được cập nhật bởi thao tác khác (revision conflict). Vui lòng tải lại dữ liệu.',
                409,
                ['current_revision' => $currentRev]
            );
        }
    }

    /**
     * @param array<string, mixed> $session
     */
    private function assertNoActiveContentJob(array $session): void
    {
        if (
            $session['active_job_id'] !== null
            || in_array((string) $session['state'], ['CAPTURING', 'RENDERING'], true)
        ) {
            throw new ApiException(
                'SESSION_BUSY',
                'Phiên đang thực hiện một tác vụ chụp hoặc xử lý ảnh khác. Vui lòng đợi tác vụ hoàn tất.',
                409
            );
        }
    }
}

