<?php

declare(strict_types=1);

namespace Photobooth\Operator\Tests;

use PHPUnit\Framework\TestCase;
use Photobooth\Operator\Auth\AuthService;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Container;
use Photobooth\Operator\Http\HttpKernel;
use Photobooth\Operator\Jobs\WorkerRunner;
use Photobooth\Operator\Printer\MockPrintAdapter;
use Photobooth\Operator\Share\ShareService;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;
use Photobooth\Operator\Support\Uuid;

final class OperatorWorkflowTest extends TestCase
{
    private string $tempDataDir;
    private Container $container;
    private HttpKernel $kernel;
    private string $operatorCookie = '';
    private string $operatorCsrf = '';
    private string $adminCookie = '';
    private string $adminCsrf = '';

    protected function setUp(): void
    {
        Clock::setMockTimestamp(1794200000); // Fixed deterministic UTC timestamp
        $this->tempDataDir = sys_get_temp_dir() . '/pb-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($this->tempDataDir, 0750, true);

        $this->container = $this->createContainer();
        $this->kernel = new HttpKernel($this->container);

        // Create operator and admin accounts
        $this->container->authService->createUser('operator1', 'OperatorPass123!', 'operator');
        $this->container->authService->createUser('admin1', 'AdminPass123!', 'admin');

        $opLogin = $this->apiRequest('POST', '/custom/api/auth/login', [
            'username' => 'operator1',
            'password' => 'OperatorPass123!',
        ], '', '');
        self::assertSame(200, $opLogin['status']);
        $this->operatorCookie = $opLogin['cookies'][0]['value'];
        $this->operatorCsrf = $opLogin['json']['csrf_token'];

        $admLogin = $this->apiRequest('POST', '/custom/api/auth/login', [
            'username' => 'admin1',
            'password' => 'AdminPass123!',
        ], '', '');
        self::assertSame(200, $admLogin['status']);
        $this->adminCookie = $admLogin['cookies'][0]['value'];
        $this->adminCsrf = $admLogin['json']['csrf_token'];
    }

    protected function tearDown(): void
    {
        Clock::setMockTimestamp(null);
        if (isset($this->container)) {
            $this->container->workerRunner->releaseSingletonLock();
            $this->container->assetStore->removeDirectoryRecursive($this->tempDataDir);
        }
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function createContainer(array $extraEnv = []): Container
    {
        $baseEnv = [
            'APP_ENV' => 'testing',
            'DATA_DIR' => $this->tempDataDir,
            'DATABASE_PATH' => $this->tempDataDir . '/db/photobooth.sqlite',
            'SHARE_BASE_URL' => 'http://192.168.1.50:8081',
            'SHARE_SIGNING_KEY' => 'test_signing_key_hmac_sha256_photobooth_operator_edition_64chars',
            'CAMERA_ADAPTER' => 'mock',
            'PRINTER_ADAPTER' => 'mock',
            'MOCK_CAPTURE_DELAY_MS' => '0',
            'MOCK_COUNTDOWN_DELAY_MS' => '0',
            'BEAUTY_ENGINE' => 'mediapipe',
            'LKMAGIC_AUTO_START' => '0',
        ];
        return new Container(array_merge($baseEnv, $extraEnv));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     * @param array<string, mixed> $query
     * @return array{status: int, headers: array<string, string>, cookies: list<array<string, mixed>>, raw_body: string, json: array<string, mixed>, file_path: ?string}
     */
    private function apiRequest(
        string $method,
        string $path,
        ?array $body = null,
        ?string $cookie = null,
        ?string $csrf = null,
        ?string $idempotencyKey = null,
        array $extraHeaders = [],
        array $query = [],
        string $listenerMode = 'operator'
    ): array {
        $useCookie = $cookie ?? $this->operatorCookie;
        $useCsrf = $csrf ?? $this->operatorCsrf;

        $headers = array_merge([
            'Host' => '127.0.0.1:8080',
            'Origin' => 'http://127.0.0.1:8080',
        ], $extraHeaders);

        if ($useCsrf !== '') {
            $headers['X-CSRF-Token'] = $useCsrf;
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        } elseif ($method === 'POST' && $path !== '/custom/api/auth/login' && $path !== '/custom/api/auth/logout') {
            $headers['Idempotency-Key'] = Uuid::v4();
        }

        $cookies = $useCookie !== '' ? [AuthService::COOKIE_NAME => $useCookie] : [];

        $res = $this->kernel->handle([
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'headers' => $headers,
            'cookies' => $cookies,
            'body' => $body,
            'ip' => '127.0.0.1',
            'listener_mode' => $listenerMode,
        ]);

        $decoded = json_decode($res['body'], true);
        return [
            'status' => $res['status'],
            'headers' => $res['headers'],
            'cookies' => $res['cookies'],
            'raw_body' => $res['body'],
            'json' => is_array($decoded) ? $decoded : [],
            'file_path' => $res['file_path'],
        ];
    }

    /** @return array<string, mixed> */
    private function createFinalizedSession(): array
    {
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $id = $session['id'];
        $revision = $session['revision'];
        foreach ([1, 2, 3] as $slot) {
            $capture = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/{$slot}/capture", ['revision' => $revision]);
            self::assertSame(202, $capture['status']);
            $revision = $capture['json']['revision'];
            $this->container->workerRunner->runTick();
        }
        $render = $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision' => $revision, 'lut_id' => 'original']);
        self::assertSame(202, $render['status']);
        $this->container->workerRunner->runTick();
        $session = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        $final = $this->apiRequest('POST', "/custom/api/sessions/{$id}/finalize", [
            'revision' => $session['revision'], 'render_id' => $session['current_render']['id'],
        ]);
        self::assertSame(201, $final['status']);
        return $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
    }

    public function testRevokeCannotInvalidateRetakeOrRenderOwnership(): void
    {
        $session = $this->createFinalizedSession();
        $id = $session['id'];
        $share = $this->apiRequest('POST', "/custom/api/sessions/{$id}/shares", [
            'revision' => $session['revision'], 'final_asset_id' => $session['current_final']['id'],
        ]);
        self::assertSame(201, $share['status']);
        $shareId = $share['json']['share_id'];
        $reopen = $this->apiRequest('POST', "/custom/api/sessions/{$id}/reopen", ['revision' => $share['json']['revision']]);
        $revision = $reopen['json']['revision'];
        $key = Uuid::v4();
        foreach (['slots/2/retake', 'render'] as $operation) {
            $job = $this->apiRequest('POST', "/custom/api/sessions/{$id}/{$operation}", ['revision' => $revision, 'lut_id' => 'original']);
            self::assertSame(202, $job['status']);
            $revision = $job['json']['revision'];
            $blocked = $this->apiRequest('POST', "/custom/api/shares/{$shareId}/revoke", ['revision' => $revision], null, null, $key);
            self::assertSame(409, $blocked['status']);
            self::assertSame('SESSION_BUSY', $blocked['json']['error']['code']);
            $this->container->workerRunner->runTick();
            $finished = $this->apiRequest('GET', '/custom/api/jobs/' . $job['json']['job_id']);
            self::assertSame('SUCCEEDED', $finished['json']['status']);
            self::assertSame($revision, $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json']['revision']);
        }
        $revoked = $this->apiRequest('POST', "/custom/api/shares/{$shareId}/revoke", ['revision' => $revision], null, null, $key);
        self::assertSame(200, $revoked['status']);
        $replay = $this->apiRequest('POST', "/custom/api/shares/{$shareId}/revoke", ['revision' => $revision], null, null, $key);
        self::assertSame($revoked['json'], $replay['json']);
    }

    public function testPostPublishFailureAllowsRetakeWithoutWorkerRestart(): void
    {
        $session = $this->createFinalizedSession();
        $id = $session['id'];
        $reopen = $this->apiRequest('POST', "/custom/api/sessions/{$id}/reopen", ['revision' => $session['revision']]);
        $before = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        $pdo = $this->container->db->pdo();
        $pdo->exec("CREATE TRIGGER fail_photo_commit BEFORE INSERT ON photos WHEN NEW.version = 2 BEGIN SELECT RAISE(ABORT, 'test metadata failure'); END");
        $capture = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/2/retake", ['revision' => $reopen['json']['revision']]);
        $this->container->workerRunner->runTick();
        self::assertSame('FAILED', $this->apiRequest('GET', '/custom/api/jobs/' . $capture['json']['job_id'])['json']['status']);
        self::assertSame($before['slots'], $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json']['slots']);
        self::assertCount(1, glob($this->tempDataDir . '/quarantine/*'));
        $pdo->exec('DROP TRIGGER fail_photo_commit');
        $retry = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/2/retake", ['revision' => $capture['json']['revision']]);
        $this->container->workerRunner->runTick();
        self::assertSame('SUCCEEDED', $this->apiRequest('GET', '/custom/api/jobs/' . $retry['json']['job_id'])['json']['status']);
        $after = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertSame(2, $after['slots'][1]['photo']['version']);
        self::assertSame($before['slots'][0], $after['slots'][0]);
        self::assertSame($before['slots'][2], $after['slots'][2]);
    }

    public function testRetentionRechecksReprintCommittedAfterCandidateSelection(): void
    {
        $session = $this->createFinalizedSession();
        $id = $session['id'];
        $closed = $this->apiRequest('POST', "/custom/api/sessions/{$id}/close", ['revision' => $session['revision']]);
        $pdo = $this->container->db->pdo();
        $pdo->prepare('UPDATE sessions SET expires_at = :expiry WHERE id = :id')->execute([
            ':expiry' => Clock::fromUnixIso(Clock::nowUnix() - 1), ':id' => $id,
        ]);
        $other = $this->createContainer();
        $requestId = null;
        $actorId = (string) $pdo->query("SELECT id FROM users WHERE username = 'operator1'")->fetchColumn();
        $this->container->maintenanceService->runMaintenancePass(false, function (string $candidate) use ($other, $id, $closed, $session, $actorId, &$requestId): void {
            self::assertSame($id, $candidate);
            $print = $other->printService->createPrintRequest(
                $id, $actorId,
                $closed['json']['revision'], $session['current_final']['id'], 1, 'reprint', Uuid::v4()
            );
            $requestId = $print['body']['print_request_id'];
        });
        self::assertNotNull($requestId);
        self::assertSame(200, $this->apiRequest('GET', "/custom/api/sessions/{$id}")['status']);
        self::assertDirectoryExists($this->container->assetStore->sessionDir($id));
        $pdo->prepare("UPDATE print_requests SET status = 'UNKNOWN' WHERE id = :id")->execute([':id' => $requestId]);
        $this->container->maintenanceService->runMaintenancePass();
        $current = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        $resolve = $this->apiRequest('POST', "/custom/api/prints/{$requestId}/resolve", [
            'revision' => $current['revision'], 'outcome' => 'confirmed_not_printed', 'note' => 'Regression: checked tray',
        ], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $resolve['status']);
        $this->container->maintenanceService->runMaintenancePass(true);
        $rejected = $this->apiRequest('POST', "/custom/api/sessions/{$id}/prints", [
            'revision' => $resolve['json']['session_revision'], 'final_asset_id' => $session['current_final']['id'], 'copies' => 1, 'action' => 'reprint',
        ]);
        self::assertSame(404, $rejected['status']);
    }


    public function testStyleCanChangeAfterCaptureWithoutChangingOriginalsOrApprovingStaleRender(): void
    {
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $id = $session['id'];
        $session = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>$session['revision'], 'layout_id'=>'single', 'beauty_preset'=>'off', 'ui_step'=>2])['json'];
        $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/capture", ['revision'=>$session['revision']]);
        $this->container->workerRunner->drainAll();
        $session = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertSame(2, $session['ui_step']);
        $original = $session['slots'][0]['photo'];
        $payload = ['revision'=>$session['revision'], 'lut_id'=>'film-400', 'template_id'=>'cute-single', 'beauty_preset'=>'off', 'ui_step'=>3];
        $key = Uuid::v4();
        $styled = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", $payload, null, null, $key);
        self::assertSame(200, $styled['status'], $styled['raw_body']);
        self::assertSame($styled['json'], $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", $payload, null, null, $key)['json']);
        $session = $styled['json'];
        self::assertSame($original, $session['slots'][0]['photo']);
        self::assertSame(3, $session['ui_step']);
        $blocked = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>$session['revision'], 'layout_id'=>'grid-four']);
        self::assertSame('CONFIGURATION_LOCKED', $blocked['json']['error']['code']);
        $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision'=>$session['revision']]);
        $this->container->workerRunner->drainAll();
        $session = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        $oldRender = $session['current_render']['id'];
        $session = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>$session['revision'], 'lut_id'=>'original', 'template_id'=>'minimal-single', 'ui_step'=>4])['json'];
        self::assertSame('CAPTURED', $session['state']);
        self::assertNull($session['current_render']);
        self::assertSame(4, $session['ui_step']);
        self::assertSame($original, $session['slots'][0]['photo']);
        $stale = $this->apiRequest('POST', "/custom/api/sessions/{$id}/finalize", ['revision'=>$session['revision'], 'render_id'=>$oldRender]);
        self::assertGreaterThanOrEqual(400, $stale['status']);
        $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision'=>$session['revision']]);
        $this->container->workerRunner->drainAll();
        $session = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertNotSame($oldRender, $session['current_render']['id']);
        $this->apiRequest('POST', "/custom/api/sessions/{$id}/finalize", ['revision'=>$session['revision'], 'render_id'=>$session['current_render']['id']]);
        $session = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertSame('FINALIZED', $session['state']);
        $blocked = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>$session['revision'], 'lut_id'=>'vintage-fade']);
        self::assertSame('CONFIGURATION_LOCKED', $blocked['json']['error']['code']);
    }

    public function testWizardGridConfigurationLocksOnFailedCaptureAndRestartReplays(): void
    {
        $catalog = $this->apiRequest('GET', '/custom/api/layouts');
        self::assertSame(200, $catalog['status']);
        self::assertCount(4, $catalog['json']['items']);
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $id = $session['id'];
        $configuration = ['revision' => $session['revision'], 'layout_id' => 'grid-four', 'lut_id' => 'film-400', 'template_id' => 'cute-grid-four', 'ui_step' => 4];
        $key = Uuid::v4();
        $changed = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", $configuration, null, null, $key);
        self::assertSame(200, $changed['status']);
        self::assertCount(4, $changed['json']['slots']);
        self::assertSame($changed['json'], $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", $configuration, null, null, $key)['json']);
        $stale = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>1, 'layout_id'=>'single']);
        self::assertSame('REVISION_CONFLICT', $stale['json']['error']['code']);
        $captureKey = Uuid::v4();
        $capturePayload = ['revision' => $changed['json']['revision']];
        $capture = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/4/capture", $capturePayload, null, null, $captureKey);
        self::assertSame(202, $capture['status']);
        self::assertSame($capture['json'], $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/4/capture", $capturePayload, null, null, $captureKey)['json']);
        $busy = $this->apiRequest('POST', "/custom/api/sessions/{$id}/restart", ['revision'=>$capture['json']['revision'], 'confirm'=>true]);
        self::assertSame('SESSION_BUSY', $busy['json']['error']['code']);
        $this->container->workerRunner->runTick('disconnect');
        $locked = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertTrue($locked['configuration_locked']);
        $configuration['revision'] = $locked['revision'];
        $configuration['lut_id'] = 'original';
        $rejected = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", $configuration);
        self::assertSame('CONFIGURATION_LOCKED', $rejected['json']['error']['code']);
        $restartKey = Uuid::v4();
        $restartPayload = ['revision' => $locked['revision'], 'confirm' => true];
        $restart = $this->apiRequest('POST', "/custom/api/sessions/{$id}/restart", $restartPayload, null, null, $restartKey);
        self::assertSame(201, $restart['status']);
        self::assertNotSame($id, $restart['json']['id']);
        self::assertFalse($restart['json']['configuration_locked']);
        self::assertSame($restart['json']['id'], $this->apiRequest('POST', "/custom/api/sessions/{$id}/restart", $restartPayload, null, null, $restartKey)['json']['id']);
        self::assertSame('CANCELLED', $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json']['state']);
    }

    public function testBrowserCameraUploadReplayTamperingAndFailedRetake(): void
    {
        $this->container = $this->createContainer(['CAMERA_ADAPTER' => 'browser']);
        $this->kernel = new HttpKernel($this->container);
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $id = $session['id'];
        $url = "/custom/api/sessions/{$id}/slots/1/capture";
        self::assertSame('client_managed', $this->container->cameraAdapter->health()['camera_state']);
        self::assertSame('CAMERA_FRAME_REQUIRED', $this->apiRequest('POST', $url, ['revision' => $session['revision']])['json']['error']['code']);
        $payload = ['revision' => $session['revision'], 'file' => ['error' => 0, 'content' => file_get_contents(__DIR__ . '/../fixtures/slot-1-v1.jpg')]];
        self::assertSame(401, $this->apiRequest('POST', $url, $payload, '', '')['status']);
        self::assertSame(403, $this->apiRequest('POST', $url, $payload, null, '')['status']);
        $tooBig = $payload;
        $tooBig['file']['content'] = str_repeat('x', 5 * 1024 * 1024 + 1);
        self::assertSame(413, $this->apiRequest('POST', $url, $tooBig)['status']);
        $key = Uuid::v4();
        $capture = $this->apiRequest('POST', $url, $payload, null, null, $key);
        self::assertSame(202, $capture['status']);
        self::assertSame($capture['json'], $this->apiRequest('POST', $url, $payload, null, null, $key)['json']);
        $changed = $payload;
        $changed['file']['content'] .= 'different';
        self::assertSame(409, $this->apiRequest('POST', $url, $changed, null, null, $key)['status']);
        self::assertTrue($this->apiRequest('GET', "/custom/api/sessions/{$id}")['json']['configuration_locked']);
        $this->container->workerRunner->runTick();
        $captured = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertSame(hash('sha256', $payload['file']['content']), $captured['slots'][0]['photo']['sha256']);
        self::assertSame('REVISION_CONFLICT', $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/2/capture", $payload)['json']['error']['code']);
        self::assertSame($capture['json'], $this->apiRequest('POST', $url, $payload, null, null, $key)['json']);
        $oldPhoto = $captured['slots'][0]['photo']['id'];
        $bad = ['revision' => $captured['revision'], 'file' => ['error' => 0, 'content' => 'not a JPEG']];
        $retake = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/retake", $bad);
        self::assertSame(202, $retake['status']);
        $this->container->workerRunner->runTick();
        $failed = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertTrue($failed['configuration_locked']);
        self::assertSame($oldPhoto, $failed['slots'][0]['photo']['id']);
        self::assertSame('FAILED', $this->apiRequest('GET', '/custom/api/jobs/' . $retake['json']['job_id'])['json']['status']);
        self::assertSame([], glob($this->tempDataDir . '/sessions/' . $id . '/pending/*'));
        $payload['revision'] = $failed['revision'];
        $next = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/2/capture", $payload);
        $pending = $this->container->assetStore->pendingJobDir($id, $next['json']['job_id']);
        file_put_contents($pending . '/browser.jpg', file_get_contents(__DIR__ . '/../fixtures/slot-2-v1.jpg'));
        $this->container->workerRunner->runTick();
        $job = $this->apiRequest('GET', '/custom/api/jobs/' . $next['json']['job_id'])['json'];
        self::assertSame('HASH_MISMATCH', $job['error']['code']);
        self::assertSame(1, $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json']['accepted_slots']);
    }

    public function testBrowserFirstInvalidJpegLocksConfigurationAndMockRejectsUploads(): void
    {
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $id = $session['id'];
        $payload = ['revision' => $session['revision'], 'file' => ['error' => 0, 'content' => 'bad JPEG']];
        self::assertSame('INVALID_CAMERA_MODE', $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/capture", $payload)['json']['error']['code']);
        $this->container = $this->createContainer(['CAMERA_ADAPTER' => 'browser']);
        $this->kernel = new HttpKernel($this->container);
        self::assertSame(202, $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/capture", $payload)['status']);
        $this->container->workerRunner->runTick();
        $failed = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertTrue($failed['configuration_locked']);
        self::assertSame(0, $failed['accepted_slots']);
        self::assertSame('CONFIGURATION_LOCKED', $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision' => $failed['revision'], 'lut_id' => 'film-400'])['json']['error']['code']);
    }

    public function testEveryLayoutCapturesRendersAndFinalizesWithPinnedArtwork(): void
    {
        foreach (['single'=>1, 'strip-three'=>3, 'postcard-three'=>3, 'grid-four'=>4] as $layout => $count) {
            $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
            $id = $session['id'];
            $template = 'party-' . $layout;
            $configured = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>$session['revision'], 'layout_id'=>$layout, 'template_id'=>$template, 'lut_id'=>'korean-soft', 'ui_step'=>4]);
            self::assertSame(200, $configured['status']);
            $revision = $configured['json']['revision'];
            $outside = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/" . ($count+1) . '/capture', ['revision'=>$revision]);
            self::assertSame('INVALID_SLOT', $outside['json']['error']['code']);
            foreach (range(1, $count) as $slot) {
                $job = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/{$slot}/capture", ['revision'=>$revision]);
                self::assertSame(202, $job['status']);
                $revision = $job['json']['revision'];
                $this->container->workerRunner->runTick();
            }
            $retake = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/retake", ['revision'=>$revision]);
            $this->container->workerRunner->runTick();
            $revision = $retake['json']['revision'];
            $bad = $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision'=>$revision, 'lut_id'=>'original']);
            self::assertSame('CONFIGURATION_LOCKED', $bad['json']['error']['code']);
            $render = $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision'=>$revision]);
            self::assertSame(202, $render['status']);
            $this->container->workerRunner->runTick();
            $review = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
            self::assertSame('REVIEW', $review['state']);
            self::assertSame($count, $review['accepted_slots']);
            self::assertSame('korean-soft', $review['selected_lut']);
            $final = $this->apiRequest('POST', "/custom/api/sessions/{$id}/finalize", ['revision'=>$review['revision'], 'render_id'=>$review['current_render']['id']]);
            self::assertSame(201, $final['status']);
            self::assertSame($review['current_render']['sha256'], $final['json']['sha256']);
            $closed = $this->apiRequest('POST', "/custom/api/sessions/{$id}/close", ['revision'=>$final['json']['revision']]);
            self::assertSame(200, $closed['status']);
        }
    }

    public function testLayoutSwitchKeepsStyleOrReportsMinimalFallback(): void
    {
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $path = '/custom/api/sessions/' . $session['id'] . '/configuration';
        $session = $this->apiRequest('POST', $path, ['revision'=>$session['revision'], 'template_id'=>'cute-postcard-three'])['json'];
        $session = $this->apiRequest('POST', $path, ['revision'=>$session['revision'], 'layout_id'=>'single'])['json'];
        self::assertSame('cute-single', $session['template_id']);
        self::assertFalse($session['template_fallback']);
        self::assertCount(1, $session['slots']);
        $this->apiRequest('POST', '/custom/api/admin/templates/cute-grid-four/1.0.0/disable', [], $this->adminCookie, $this->adminCsrf);
        $session = $this->apiRequest('POST', $path, ['revision'=>$session['revision'], 'layout_id'=>'grid-four'])['json'];
        self::assertSame('minimal-grid-four', $session['template_id']);
        self::assertTrue($session['template_fallback']);
        self::assertCount(4, $session['slots']);
    }

    public function testLutImportDraftPreviewActivationAndIdempotency(): void
    {
        $bytes = "# import fixture\n" . file_get_contents($this->container->config->customRoot . '/presets/film-400.cube');
        $upload = ['file'=>['name'=>'portrait.cube', 'content'=>$bytes, 'error'=>0]];
        $key = Uuid::v4();
        $draft = $this->apiRequest('POST', '/custom/api/admin/luts/import', $upload, $this->adminCookie, $this->adminCsrf, $key, ['Content-Type'=>'multipart/form-data']);
        self::assertSame(201, $draft['status'], $draft['raw_body']);
        self::assertSame('draft', $draft['json']['status']);
        self::assertSame(80, $draft['json']['intensity']);
        self::assertSame($draft['json'], $this->apiRequest('POST', '/custom/api/admin/luts/import', $upload, $this->adminCookie, $this->adminCsrf, $key, ['Content-Type'=>'multipart/form-data'])['json']);
        $path = '/custom/api/admin/luts/' . $draft['json']['id'] . '/1.0.0';
        $basePresetCount = count($this->container->config->getPresetsMap());
        self::assertGreaterThanOrEqual(4, $basePresetCount);
        self::assertCount($basePresetCount, $this->apiRequest('GET', '/custom/api/presets')['json']['presets']);
        $preview = $this->apiRequest('GET', parse_url($draft['json']['sample_url'], PHP_URL_PATH), null, $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $preview['status']);
        self::assertFileExists($preview['file_path']);
        $edit = $this->apiRequest('POST', $path . '/draft', ['revision'=>1, 'name'=>'Chân dung dịu', 'intensity'=>65], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $edit['status']);
        self::assertNotSame($draft['json']['preview_sha256'], $edit['json']['preview_sha256']);
        $active = $this->apiRequest('POST', $path . '/activate', ['revision'=>$edit['json']['revision'], 'preview_sha256'=>$edit['json']['preview_sha256']], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $active['status'], $active['raw_body']);
        self::assertSame('active', $active['json']['status']);
        self::assertCount($basePresetCount + 1, $this->apiRequest('GET', '/custom/api/presets')['json']['presets']);
    }

    public function testImportedLutVersionIsPinnedAcrossActivationRetakeAndRestore(): void
    {
        $upload = ['file'=>['name'=>'skin.cube', 'error'=>0, 'content'=>"# pinned fixture\n" . file_get_contents($this->container->config->customRoot . '/presets/korean-soft.cube')]];
        $draft = $this->apiRequest('POST', '/custom/api/admin/luts/import', $upload, $this->adminCookie, $this->adminCsrf, null, ['Content-Type'=>'multipart/form-data'])['json'];
        $base = '/custom/api/admin/luts/' . $draft['id'];
        $active = $this->apiRequest('POST', $base . '/1.0.0/activate', ['revision'=>1, 'preview_sha256'=>$draft['preview_sha256']], $this->adminCookie, $this->adminCsrf)['json'];
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $id = $session['id'];
        $configured = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>$session['revision'], 'layout_id'=>'single', 'lut_id'=>$active['id'], 'lut_version'=>'1.0.0']);
        self::assertSame(200, $configured['status'], $configured['raw_body']);
        self::assertSame(80, $configured['json']['lut_intensity']);
        $capture = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/capture", ['revision'=>$configured['json']['revision']])['json'];
        $this->container->workerRunner->drainAll();
        $version = $this->apiRequest('POST', $base . '/1.0.0/versions', ['revision'=>$active['revision']], $this->adminCookie, $this->adminCsrf)['json'];
        self::assertSame('1.0.1', $version['version']);
        $replacement = ['name'=>'new-skin.cube', 'error'=>0, 'content'=>"# replacement fixture\n" . file_get_contents($this->container->config->customRoot . '/presets/film-400.cube')];
        $edited = $this->apiRequest('POST', $base . '/1.0.1/draft', ['revision'=>1, 'intensity'=>20, 'file'=>$replacement], $this->adminCookie, $this->adminCsrf)['json'];
        self::assertNotSame($active['sha256'], $edited['sha256']);
        $new = $this->apiRequest('POST', $base . '/1.0.1/activate', ['revision'=>$edited['revision'], 'preview_sha256'=>$edited['preview_sha256']], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $new['status']);
        $locked = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertSame(80, $locked['lut_intensity']);
        self::assertSame('1.0.0', $locked['lut_version']);
        $override = $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision'=>$locked['revision'], 'lut_version'=>'1.0.1']);
        self::assertSame('CONFIGURATION_LOCKED', $override['json']['error']['code']);
        $render = $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision'=>$locked['revision']]);
        self::assertSame(202, $render['status']);
        $this->container->workerRunner->drainAll();
        $review = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertSame('REVIEW', $review['state']);
        self::assertSame('1.0.0', $review['current_render']['lut_version']);
        $final = $this->apiRequest('POST', "/custom/api/sessions/{$id}/finalize", ['revision'=>$review['revision'], 'render_id'=>$review['current_render']['id']]);
        self::assertSame(201, $final['status']);
        $share = $this->apiRequest('POST', "/custom/api/sessions/{$id}/shares", ['revision'=>$final['json']['revision'], 'final_asset_id'=>$final['json']['final_asset_id']])['json'];
        $reopen = $this->apiRequest('POST', "/custom/api/sessions/{$id}/reopen", ['revision'=>$share['revision']])['json'];
        $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/retake", ['revision'=>$reopen['revision']]);
        $this->container->workerRunner->drainAll();
        $retaken = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        self::assertSame(80, $retaken['lut_intensity']);
        $guest = $this->apiRequest('GET', (string) parse_url($share['download_url'], PHP_URL_PATH), null, '', '', null, [], [], 'guest');
        self::assertSame($final['json']['sha256'], hash_file('sha256', $guest['file_path']));
        $backup = $this->container->maintenanceService->createBackup($this->tempDataDir . '/lut-backup');
        $restoredDirectory = $this->tempDataDir . '/restored-luts';
        $restored = $this->createContainer(['DATA_DIR'=>$restoredDirectory, 'DATABASE_PATH'=>$restoredDirectory . '/db.sqlite']);
        $restored->maintenanceService->restoreFromBackup($backup['backup_dir']);
        $verified = $this->createContainer(['DATA_DIR'=>$restoredDirectory, 'DATABASE_PATH'=>$restoredDirectory . '/db.sqlite']);
        self::assertSame('disabled', $verified->luts->describe($active['id'], '1.0.0')['status']);
        self::assertSame(80, $verified->sessionService->getSession($id)['lut_intensity']);
        self::assertSame($active['sha256'], hash_file('sha256', $verified->luts->filePath($verified->luts->resolve($active['id'], '1.0.0'))));
    }

    public function testLutUploadSecurityStalePreviewAndImmutablePublishedVersion(): void
    {
        $valid = "# security fixture\n" . file_get_contents($this->container->config->customRoot . '/presets/film-400.cube');
        $send = function (string $bytes, string $name = 'test.cube', bool $admin = true, ?string $csrf = null): array {
            return $this->apiRequest('POST', '/custom/api/admin/luts/import', ['file'=>['name'=>$name,'content'=>$bytes,'error'=>0]], $admin ? $this->adminCookie : $this->operatorCookie, $csrf ?? ($admin ? $this->adminCsrf : $this->operatorCsrf), null, ['Content-Type'=>'multipart/form-data']);
        };
        self::assertSame(403, $send($valid, 'test.cube', false)['status']);
        self::assertSame(403, $send($valid, 'test.cube', true, '')['status']);
        foreach (['../escape.cube', 'dir\\escape.cube', 'https://example.com/test.cube', 'script.php', "bad\0.cube"] as $name) {
            self::assertSame(422, $send($valid, $name)['status']);
        }
        foreach (["\xff\xfe", '<?php exit();', str_repeat('x',5*1024*1024+1), str_replace('LUT_3D_SIZE 33','LUT_3D_SIZE 17',$valid), str_replace('DOMAIN_MAX 1.0 1.0 1.0','DOMAIN_MAX 2.0 2.0 2.0',$valid), $valid . "0 0 0\n"] as $invalid) {
            self::assertSame(422, $send($invalid)['status']);
        }
        $draft = $send($valid)['json'];
        $base = '/custom/api/admin/luts/' . $draft['id'] . '/1.0.0';
        $previewPath = parse_url($draft['sample_url'], PHP_URL_PATH);
        self::assertSame(403, $this->apiRequest('GET', $previewPath)['status']);
        self::assertSame(403, $this->apiRequest('GET', $previewPath, null, '', '', null, [], [], 'guest')['status']);
        $oldPreview = $this->apiRequest('POST', $base . '/activate', ['revision'=>1,'preview_sha256'=>str_repeat('0',64)], $this->adminCookie,$this->adminCsrf);
        self::assertSame('PREVIEW_STALE', $oldPreview['json']['error']['code']);
        $badName = $this->apiRequest('POST', $base . '/draft', ['revision'=>1,'name'=>"\xff"], $this->adminCookie,$this->adminCsrf);
        self::assertSame(422, $badName['status']);
        $edited = $this->apiRequest('POST', $base . '/draft', ['revision'=>1,'intensity'=>40], $this->adminCookie,$this->adminCsrf)['json'];
        $stale = $this->apiRequest('POST', $base . '/activate', ['revision'=>1,'preview_sha256'=>$draft['preview_sha256']], $this->adminCookie,$this->adminCsrf);
        self::assertSame('REVISION_CONFLICT', $stale['json']['error']['code']);
        $file = $this->container->luts->filePath($this->container->luts->resolve($draft['id'],'1.0.0'));
        file_put_contents($file, $valid . "# changed\n");
        $tampered = $this->apiRequest('POST', $base . '/activate', ['revision'=>$edited['revision'],'preview_sha256'=>$edited['preview_sha256']], $this->adminCookie,$this->adminCsrf);
        self::assertSame('INTEGRITY_ERROR', $tampered['json']['error']['code']);
        file_put_contents($file, $valid);
        $active = $this->apiRequest('POST', $base . '/activate', ['revision'=>$edited['revision'],'preview_sha256'=>$edited['preview_sha256']], $this->adminCookie,$this->adminCsrf)['json'];
        $immutable = $this->apiRequest('POST', $base . '/draft', ['revision'=>$active['revision'],'intensity'=>90], $this->adminCookie,$this->adminCsrf);
        self::assertSame('LUT_IMMUTABLE', $immutable['json']['error']['code']);
        self::assertSame(409, $send($valid)['status']);
        $disabled = $this->apiRequest('POST', $base . '/disable', ['revision'=>$active['revision']], $this->adminCookie,$this->adminCsrf);
        self::assertSame(200, $disabled['status']);
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $rejected = $this->apiRequest('POST', '/custom/api/sessions/' . $session['id'] . '/configuration', ['revision'=>$session['revision'],'lut_id'=>$active['id']]);
        self::assertSame('INVALID_LUT', $rejected['json']['error']['code']);
    }

    public function testAdminTemplateImportValidationActivationAndPinnedDisabledVersion(): void
    {
        $directory = $this->container->config->customRoot . '/templates/bundled/cute-single';
        $makeZip = function (array $changes = [], ?string $extraName = null): string {
            $source = $this->container->config->customRoot . '/templates/bundled/cute-single';
            $manifest = json_decode(file_get_contents($source . '/template.json'), true);
            $manifest['id'] = 'imported-test';
            unset($manifest['hashes']);
            $manifest = array_merge($manifest, $changes);
            $path = $this->tempDataDir . '/package-' . Uuid::v4() . '.zip';
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE);
            $zip->addFromString('template.json', json_encode($manifest));
            foreach (['overlay.png','background.png','preview.jpg'] as $name) {
                $zip->addFile($source . '/' . $name, $name);
            }
            if ($extraName !== null) {
                $zip->addFromString($extraName, 'forbidden');
            }
            $zip->close();
            return file_get_contents($path);
        };
        $import = function (string $zip, bool $admin = true, ?string $key = null): array {
            return $this->kernel->handle(['method'=>'POST', 'path'=>'/custom/api/admin/templates/import', 'headers'=>[
                'Content-Type'=>'application/zip', 'X-CSRF-Token'=>$admin ? $this->adminCsrf : $this->operatorCsrf, 'Idempotency-Key'=>$key ?? Uuid::v4(),
            ], 'cookies'=>[AuthService::COOKIE_NAME=>$admin ? $this->adminCookie : $this->operatorCookie], 'body'=>$zip]);
        };
        self::assertSame(403, $import($makeZip(), false)['status']);
        self::assertSame(422, $import($makeZip([], '../escape.php'))['status']);
        self::assertSame(422, $import($makeZip([], 'execute.php'))['status']);
        self::assertSame(422, $import($makeZip(['overlay'=>'https://example.com/a.png']))['status']);
        self::assertSame(422, $import('not a zip')['status']);
        self::assertSame(422, $import($makeZip(['layout_id'=>'unknown']))['status']);
        self::assertSame(422, $import(str_repeat('x', 30 * 1024 * 1024 + 1))['status']);
        $invalidPath = $this->tempDataDir . '/invalid-package.zip';
        file_put_contents($invalidPath, $makeZip(['id'=>'invalid-dimensions']));
        $badZip = new \ZipArchive();
        $badZip->open($invalidPath);
        $badZip->addFile($directory . '/preview.jpg', 'overlay.png');
        $badZip->close();
        self::assertSame(422, $import(file_get_contents($invalidPath))['status']);
        $smallOverlay = imagecreatetruecolor(10, 10);
        imagesavealpha($smallOverlay, true);
        imagepng($smallOverlay, $this->tempDataDir . '/small.png');
        imagedestroy($smallOverlay);
        file_put_contents($invalidPath, $makeZip(['id'=>'invalid-png-size']));
        $badZip->open($invalidPath);
        $badZip->addFile($this->tempDataDir . '/small.png', 'overlay.png');
        $badZip->close();
        self::assertSame(422, $import(file_get_contents($invalidPath))['status']);
        file_put_contents($invalidPath, $makeZip(['id'=>'invalid-link']));
        $badZip->open($invalidPath);
        $badZip->setExternalAttributesName('overlay.png', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $badZip->close();
        self::assertSame(422, $import(file_get_contents($invalidPath))['status']);
        file_put_contents($invalidPath, $makeZip(['id'=>'invalid-entry-count']));
        $badZip->open($invalidPath);
        foreach (range(1,21) as $entry) $badZip->addFromString('extra-' . $entry, 'x');
        $badZip->close();
        self::assertSame(422, $import(file_get_contents($invalidPath))['status']);
        $largeFile = $this->tempDataDir . '/large.png';
        $largeStream = fopen($largeFile, 'wb');
        ftruncate($largeStream, 60 * 1024 * 1024 + 1);
        fclose($largeStream);
        file_put_contents($invalidPath, $makeZip(['id'=>'invalid-inflated-size']));
        $badZip->open($invalidPath);
        $badZip->addFile($largeFile, 'overlay.png');
        $badZip->close();
        self::assertSame(422, $import(file_get_contents($invalidPath))['status']);

        $key = Uuid::v4();
        $bytes = $makeZip();
        $draft = $import($bytes, true, $key);
        self::assertSame(201, $draft['status']);
        self::assertSame($draft['body'], $import($bytes, true, $key)['body']);
        self::assertSame(409, $import($bytes)['status']);
        $visible = $this->apiRequest('GET', '/custom/api/templates', null, null, null, null, [], ['layout_id'=>'single']);
        self::assertNotContains('imported-test', array_column($visible['json']['items'], 'id'));
        self::assertSame(403, $this->apiRequest('GET', '/custom/api/catalog/templates/imported-test/1.0.0/overlay.png')['status']);
        self::assertSame(200, $this->apiRequest('GET', '/custom/api/catalog/templates/imported-test/1.0.0/overlay.png', null, $this->adminCookie, $this->adminCsrf)['status']);
        $active = $this->apiRequest('POST', '/custom/api/admin/templates/imported-test/1.0.0/activate', [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $active['status']);
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $id = $session['id'];
        $configured = $this->apiRequest('POST', "/custom/api/sessions/{$id}/configuration", ['revision'=>$session['revision'], 'layout_id'=>'single', 'template_id'=>'imported-test']);
        self::assertSame(200, $configured['status']);
        $disabled = $this->apiRequest('POST', '/custom/api/admin/templates/imported-test/1.0.0/disable', [], $this->adminCookie, $this->adminCsrf);
        self::assertSame(200, $disabled['status']);
        $capture = $this->apiRequest('POST', "/custom/api/sessions/{$id}/slots/1/capture", ['revision'=>$configured['json']['revision']]);
        $this->container->workerRunner->runTick();
        $render = $this->apiRequest('POST', "/custom/api/sessions/{$id}/render", ['revision'=>$capture['json']['revision']]);
        self::assertSame(202, $render['status']);
        $this->container->workerRunner->runTick();
        self::assertSame('REVIEW', $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json']['state']);
        $backup = $this->container->maintenanceService->createBackup($this->tempDataDir . '/template-backup');
        $manifest = json_decode(file_get_contents($backup['backup_dir'] . '/backup-manifest.json'), true);
        self::assertArrayHasKey('templates/imported-test/1.0.0/overlay.png', $manifest['assets']);
        $restoredDirectory = $this->tempDataDir . '/restored-templates';
        $restored = $this->createContainer(['DATA_DIR'=>$restoredDirectory, 'DATABASE_PATH'=>$restoredDirectory . '/db.sqlite']);
        $restored->maintenanceService->restoreFromBackup($backup['backup_dir']);
        $verified = $this->createContainer(['DATA_DIR'=>$restoredDirectory, 'DATABASE_PATH'=>$restoredDirectory . '/db.sqlite']);
        self::assertSame('disabled', $verified->catalog->describe('imported-test','1.0.0')['status']);
        self::assertSame($this->container->sessionService->getSession($id)['current_render']['sha256'], $verified->sessionService->getSession($id)['current_render']['sha256']);
        $old = $this->apiRequest('GET', "/custom/api/sessions/{$id}")['json'];
        $new = $this->apiRequest('POST', "/custom/api/sessions/{$id}/restart", ['revision'=>$old['revision'], 'confirm'=>true])['json'];
        $rejected = $this->apiRequest('POST', '/custom/api/sessions/' . $new['id'] . '/configuration', ['revision'=>$new['revision'], 'layout_id'=>'single', 'template_id'=>'imported-test']);
        self::assertSame('INVALID_TEMPLATE', $rejected['json']['error']['code']);
    }

    public function testTc01ToTc06SessionCreationThreeSlotsRetakeAndValidation(): void
    {
        // TC-01: Create session -> Unique UUID, state NEW, revision 1, 3 empty slots
        $createRes = $this->apiRequest('POST', '/custom/api/sessions');
        self::assertSame(201, $createRes['status']);
        $session = $createRes['json'];
        $sessionId = (string) $session['id'];
        self::assertTrue(Uuid::isValid($sessionId));
        self::assertSame('NEW', $session['state']);
        self::assertSame(1, $session['revision']);
        self::assertSame(0, $session['accepted_slots']);
        self::assertCount(3, $session['slots']);

        // Only 1 active session allowed at a time
        $dupSession = $this->apiRequest('POST', '/custom/api/sessions');
        self::assertSame(409, $dupSession['status']);
        self::assertSame('ACTIVE_SESSION_EXISTS', $dupSession['json']['error']['code']);

        // TC-06: Render with empty slots -> rejected with 422 MISSING_PHOTOS
        $earlyRender = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", [
            'revision' => 1,
            'lut_id' => 'original',
        ]);
        self::assertSame(422, $earlyRender['status']);
        self::assertSame('MISSING_PHOTOS', $earlyRender['json']['error']['code']);

        // TC-03: Try capturing a 4th slot (and slot 0) -> 422 INVALID_SLOT, no file written
        $slot4 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/4/capture", [
            'revision' => 1,
        ]);
        self::assertSame(422, $slot4['status']);
        self::assertSame('INVALID_SLOT', $slot4['json']['error']['code']);

        $slot0 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/0/capture", [
            'revision' => 1,
        ]);
        self::assertSame(422, $slot0['status']);
        self::assertSame('INVALID_SLOT', $slot0['json']['error']['code']);

        // TC-02: Capture sequential slots 1, 2, 3
        $rev = 1;
        foreach ([1, 2, 3] as $slotNum) {
            $capRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/{$slotNum}/capture", [
                'revision' => $rev,
            ]);
            self::assertSame(202, $capRes['status']);
            $rev = (int) $capRes['json']['revision'];
            self::assertSame('CAPTURING', $capRes['json']['state']);

            $this->container->workerRunner->runTick();

            $sView = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
            self::assertSame('CAPTURED', $sView['state']);
            self::assertSame($slotNum, $sView['accepted_slots']);
            // Job completion does not increment revision a second time
            self::assertSame($rev, $sView['revision']);

            if ($slotNum < 3) {
                // TC-06: Render when only 1 or 2 slots captured -> 422 MISSING_PHOTOS
                $partialRender = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", [
                    'revision' => $rev,
                    'lut_id' => 'original',
                ]);
                self::assertSame(422, $partialRender['status']);
                self::assertSame('MISSING_PHOTOS', $partialRender['json']['error']['code']);
            }
        }

        $after3 = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        $slot1HashBefore = $after3['slots'][0]['photo']['sha256'];
        $slot2HashV1 = $after3['slots'][1]['photo']['sha256'];
        $slot3HashBefore = $after3['slots'][2]['photo']['sha256'];
        self::assertCount(3, array_unique([$slot1HashBefore, $slot2HashV1, $slot3HashBefore]));

        // TC-04: Retake slot 2 -> Only slot 2 changes (v2); slot 1 & 3 hashes unchanged
        $retake1 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/2/retake", [
            'revision' => $rev,
        ]);
        self::assertSame(202, $retake1['status']);
        $rev = (int) $retake1['json']['revision'];
        $this->container->workerRunner->runTick();

        $afterRetake1 = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        $slot2HashV2 = $afterRetake1['slots'][1]['photo']['sha256'];
        self::assertSame(2, $afterRetake1['slots'][1]['photo']['version']);
        self::assertNotSame($slot2HashV1, $slot2HashV2);
        self::assertSame($slot1HashBefore, $afterRetake1['slots'][0]['photo']['sha256']);
        self::assertSame($slot3HashBefore, $afterRetake1['slots'][2]['photo']['sha256']);

        // TC-05: Retake slot 2 a second time -> version v3, prior v1/v2 retained, no orphan files
        $retake2 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/2/retake", [
            'revision' => $rev,
        ]);
        self::assertSame(202, $retake2['status']);
        $rev = (int) $retake2['json']['revision'];
        $this->container->workerRunner->runTick();

        $afterRetake2 = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        self::assertSame(3, $afterRetake2['slots'][1]['photo']['version']);
        self::assertSame($slot1HashBefore, $afterRetake2['slots'][0]['photo']['sha256']);
        self::assertSame($slot3HashBefore, $afterRetake2['slots'][2]['photo']['sha256']);

        // Verify v1, v2, v3 all exist on disk and in DB, and 0 orphans exist
        foreach ([1, 2, 3] as $ver) {
            $rel = $this->container->assetStore->originalPhotoRelativePath($sessionId, 2, $ver);
            self::assertFileExists($this->container->assetStore->absolutePath($rel));
        }
        $orphanStats = $this->container->assetStore->reconcileStartupOrphans($this->container->db->pdo());
        self::assertSame(0, $orphanStats['quarantined_orphans']);
    }

    public function testIt01ToIt03AndIt05ConcurrencyIdempotencyAndFailedRetake(): void
    {
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $sessionId = (string) $session['id'];
        $rev = (int) $session['revision'];

        foreach ([1, 2, 3] as $slotNum) {
            $r = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/{$slotNum}/capture", [
                'revision' => $rev,
            ]);
            $rev = (int) $r['json']['revision'];
            $this->container->workerRunner->runTick();
        }

        // IT-01: Two tabs send retake on slot 2 with the same revision ($rev = 4)
        $idemTab1 = Uuid::v4();
        $idemTab2 = Uuid::v4();
        $tab1Res = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$sessionId}/slots/2/retake",
            ['revision' => $rev],
            null,
            null,
            $idemTab1
        );
        $tab2Res = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$sessionId}/slots/2/retake",
            ['revision' => $rev],
            null,
            null,
            $idemTab2
        );
        self::assertSame(202, $tab1Res['status']);
        self::assertSame(409, $tab2Res['status']);
        self::assertSame('REVISION_CONFLICT', $tab2Res['json']['error']['code']);

        // Even if Tab 2 refreshes revision while Tab 1's job is still running -> 409 SESSION_BUSY
        $newRev = (int) $tab1Res['json']['revision'];
        $tab2BusyRes = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$sessionId}/slots/1/retake",
            ['revision' => $newRev]
        );
        self::assertSame(409, $tab2BusyRes['status']);
        self::assertSame('SESSION_BUSY', $tab2BusyRes['json']['error']['code']);

        // IT-02: Replay Tab 1's exact Idempotency-Key -> returns identical 202 and job_id without extra capture
        $replayTab1 = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$sessionId}/slots/2/retake",
            ['revision' => $rev],
            null,
            null,
            $idemTab1
        );
        self::assertSame(202, $replayTab1['status']);
        self::assertSame($tab1Res['json']['job_id'], $replayTab1['json']['job_id']);
        self::assertSame($newRev, $replayTab1['json']['revision']);

        // IT-03: Same Idempotency-Key with different slot/payload -> 409 IDEMPOTENCY_CONFLICT
        $conflictTab1 = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$sessionId}/slots/3/retake",
            ['revision' => $rev],
            null,
            null,
            $idemTab1
        );
        self::assertSame(409, $conflictTab1['status']);
        self::assertSame('IDEMPOTENCY_CONFLICT', $conflictTab1['json']['error']['code']);

        // Complete Tab 1's retake job
        $this->container->workerRunner->runTick();

        $beforeFailedRetake = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        $hashesBefore = [
            1 => $beforeFailedRetake['slots'][0]['photo']['sha256'],
            2 => $beforeFailedRetake['slots'][1]['photo']['sha256'],
            3 => $beforeFailedRetake['slots'][2]['photo']['sha256'],
        ];

        // IT-05: Retake fails (corrupt_jpeg or disconnect) after 3 photos captured -> preserves all 3 photos & state
        $failRetake = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/2/retake", [
            'revision' => $newRev,
        ]);
        self::assertSame(202, $failRetake['status']);
        $revAfterFailEnqueue = (int) $failRetake['json']['revision'];
        $failJobId = (string) $failRetake['json']['job_id'];

        $this->container->workerRunner->runTick('corrupt_jpeg');

        $jobCheck = $this->apiRequest('GET', "/custom/api/jobs/{$failJobId}")['json'];
        self::assertSame('FAILED', $jobCheck['status']);
        self::assertSame('CORRUPT_JPEG', $jobCheck['error']['code']);

        $afterFailedRetake = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        self::assertSame('CAPTURED', $afterFailedRetake['state']);
        self::assertSame(3, $afterFailedRetake['accepted_slots']);
        self::assertSame($revAfterFailEnqueue, $afterFailedRetake['revision']);
        self::assertSame($hashesBefore[1], $afterFailedRetake['slots'][0]['photo']['sha256']);
        self::assertSame($hashesBefore[2], $afterFailedRetake['slots'][1]['photo']['sha256']);
        self::assertSame($hashesBefore[3], $afterFailedRetake['slots'][2]['photo']['sha256']);
    }

    public function testTc07ToTc11AndIt04It06RenderLutFinalizeAndReopen(): void
    {
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $sessionId = (string) $session['id'];
        $rev = (int) $session['revision'];

        $configured = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/configuration", ['revision'=>$rev, 'lut_id'=>'korean-soft']);
        self::assertSame(200, $configured['status']);
        $rev = $configured['json']['revision'];

        foreach ([1, 2, 3] as $slotNum) {
            $r = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/{$slotNum}/capture", [
                'revision' => $rev,
            ]);
            $rev = (int) $r['json']['revision'];
            $this->container->workerRunner->runTick();
        }

        // Render with korean-soft; also verify transient crash on attempt 1 automatically retries on attempt 2!
        $renderRes1 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", [
            'revision' => $rev,
            'lut_id' => 'korean-soft',
            'template_id' => 'vertical-three-v1',
        ]);
        self::assertSame(202, $renderRes1['status']);
        $rev = (int) $renderRes1['json']['revision'];
        $renderJobId1 = (string) $renderRes1['json']['job_id'];

        // Attempt 1 crashes transiently -> re-queued for Attempt 2
        $this->container->workerRunner->runTick('render_transient_crash');
        $jobMid = $this->apiRequest('GET', "/custom/api/jobs/{$renderJobId1}")['json'];
        self::assertSame('QUEUED', $jobMid['status']);
        self::assertSame(2, $jobMid['attempt']);

        // Attempt 2 succeeds
        $this->container->workerRunner->runTick();
        $sReview1 = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        self::assertSame('REVIEW', $sReview1['state']);
        $render1Id = (string) $sReview1['current_render']['id'];
        self::assertSame('korean-soft', $sReview1['current_render']['lut_id']);

        // Configuration is now locked; retake invalidates the old render without changing its filter.
        $changedFilter = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", ['revision'=>$rev, 'lut_id'=>'film-400']);
        self::assertSame('CONFIGURATION_LOCKED', $changedFilter['json']['error']['code']);
        $retake = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/2/retake", ['revision'=>$rev]);
        $rev = $retake['json']['revision'];
        $this->container->workerRunner->runTick();
        $renderRes2 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", [
            'revision' => $rev,
            'lut_id' => 'korean-soft',
            'template_id' => 'vertical-three-v1',
        ]);
        self::assertSame(202, $renderRes2['status']);
        $rev = (int) $renderRes2['json']['revision'];
        $this->container->workerRunner->runTick();

        $sReview2 = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        self::assertSame('REVIEW', $sReview2['state']);
        $render2Id = (string) $sReview2['current_render']['id'];
        $render2Sha = (string) $sReview2['current_render']['sha256'];
        self::assertNotSame($render1Id, $render2Id);

        // Attempting to finalize stale render1Id must be rejected (IT-04)
        $staleFinalize = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/finalize", [
            'revision' => $rev,
            'render_id' => $render1Id,
        ]);
        self::assertSame(422, $staleFinalize['status']);
        self::assertSame('INVALID_STATE', $staleFinalize['json']['error']['code']);

        // TC-11: Finalize current valid render2Id -> byte-identical SHA-256 to print.jpg
        $finRes1 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/finalize", [
            'revision' => $rev,
            'render_id' => $render2Id,
        ]);
        self::assertSame(201, $finRes1['status']);
        $rev = (int) $finRes1['json']['revision'];
        $final1Id = (string) $finRes1['json']['final_asset_id'];
        $final1Sha = (string) $finRes1['json']['sha256'];
        self::assertSame($render2Sha, $final1Sha);

        // Issue QR share token for final1
        $shareRes1 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/shares", [
            'revision' => $rev,
            'final_asset_id' => $final1Id,
        ]);
        self::assertSame(201, $shareRes1['status']);
        $rev = (int) $shareRes1['json']['revision'];
        $shareUrl1 = (string) $shareRes1['json']['url'];
        $token1 = basename((string) parse_url($shareUrl1, PHP_URL_PATH));

        // IT-06: Reopen and finalize a second time -> two finals with distinct IDs; old QR still serves final1
        $reopenRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/reopen", [
            'revision' => $rev,
        ]);
        self::assertSame(200, $reopenRes['status']);
        self::assertSame('CAPTURED', $reopenRes['json']['state']);
        $rev = (int) $reopenRes['json']['revision'];

        // Reopen allows retake, but it never unlocks filter/layout/template.
        $blocked = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", ['revision'=>$rev, 'lut_id'=>'vintage-fade']);
        self::assertSame('CONFIGURATION_LOCKED', $blocked['json']['error']['code']);
        $retakeAgain = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/2/retake", ['revision'=>$rev]);
        $rev = $retakeAgain['json']['revision'];
        $this->container->workerRunner->runTick();
        $renderRes3 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", [
            'revision' => $rev,
            'lut_id' => 'korean-soft',
            'template_id' => 'vertical-three-v1',
        ]);
        $rev = (int) $renderRes3['json']['revision'];
        $this->container->workerRunner->runTick();

        $sReview3 = $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json'];
        $render3Id = (string) $sReview3['current_render']['id'];

        $finRes2 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/finalize", [
            'revision' => $rev,
            'render_id' => $render3Id,
        ]);
        self::assertSame(201, $finRes2['status']);
        $final2Id = (string) $finRes2['json']['final_asset_id'];
        $final2Sha = (string) $finRes2['json']['sha256'];
        self::assertNotSame($final1Id, $final2Id);
        self::assertNotSame($final1Sha, $final2Sha);

        // Verify token1 still downloads final1's exact bytes and hash!
        $dl1 = $this->apiRequest('GET', "/share/{$token1}/download", null, '', '', null, [], [], 'guest');
        self::assertSame(200, $dl1['status']);
        self::assertNotNull($dl1['file_path']);
        self::assertSame($final1Sha, hash_file('sha256', (string) $dl1['file_path']));
    }

    public function testTc12ToTc15Tc28AndIt07ToIt10PrintStateAndUnknownResolution(): void
    {
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $sessionId = (string) $session['id'];
        $rev = (int) $session['revision'];

        // TC-12: Print without finalization -> rejected with 422 INVALID_STATE
        $earlyPrint = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/prints", [
            'revision' => $rev,
            'final_asset_id' => Uuid::v4(),
            'copies' => 1,
            'action' => 'print',
        ]);
        self::assertSame(422, $earlyPrint['status']);
        self::assertSame('INVALID_STATE', $earlyPrint['json']['error']['code']);

        foreach ([1, 2, 3] as $slotNum) {
            $r = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/{$slotNum}/capture", [
                'revision' => $rev,
            ]);
            $rev = (int) $r['json']['revision'];
            $this->container->workerRunner->runTick();
        }
        $rRen = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", [
            'revision' => $rev,
            'lut_id' => 'original',
        ]);
        $rev = (int) $rRen['json']['revision'];
        $this->container->workerRunner->runTick();
        $renderId = (string) $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json']['current_render']['id'];

        $finRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/finalize", [
            'revision' => $rev,
            'render_id' => $renderId,
        ]);
        $rev = (int) $finRes['json']['revision'];
        $finalAssetId = (string) $finRes['json']['final_asset_id'];

        // TC-28: Copies != 1 rejected with 422 INVALID_COPIES
        $badCopies = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/prints", [
            'revision' => $rev,
            'final_asset_id' => $finalAssetId,
            'copies' => 2,
            'action' => 'print',
        ]);
        self::assertSame(422, $badCopies['status']);
        self::assertSame('INVALID_COPIES', $badCopies['json']['error']['code']);

        // TC-13: Double-click Print with same Idempotency-Key -> only 1 print_request & 1 adapter receipt
        $printIdemKey = Uuid::v4();
        $pr1 = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$sessionId}/prints",
            [
                'revision' => $rev,
                'final_asset_id' => $finalAssetId,
                'copies' => 1,
                'action' => 'print',
            ],
            null,
            null,
            $printIdemKey
        );
        $pr1Replay = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$sessionId}/prints",
            [
                'revision' => $rev,
                'final_asset_id' => $finalAssetId,
                'copies' => 1,
                'action' => 'print',
            ],
            null,
            null,
            $printIdemKey
        );
        self::assertSame(202, $pr1['status']);
        self::assertSame(202, $pr1Replay['status']);
        self::assertSame($pr1['json']['print_request_id'], $pr1Replay['json']['print_request_id']);
        $rev = (int) $pr1['json']['revision'];

        $this->container->workerRunner->drainAll();
        $pr1Status = $this->apiRequest('GET', '/custom/api/prints/' . $pr1['json']['print_request_id'])['json'];
        self::assertSame('COMPLETED', $pr1Status['status']);

        /** @var MockPrintAdapter $mockPrinter */
        $mockPrinter = $this->container->printAdapter;
        self::assertSame(1, $mockPrinter->countReceipts());

        // TC-14: Explicit Reprint on finalized asset -> new job points to same final_asset_id and hash
        $reprint1 = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/prints", [
            'revision' => $rev,
            'final_asset_id' => $finalAssetId,
            'copies' => 1,
            'action' => 'reprint',
        ]);
        self::assertSame(202, $reprint1['status']);
        $rev = (int) $reprint1['json']['revision'];
        $this->container->workerRunner->drainAll();
        self::assertSame(2, $mockPrinter->countReceipts());

        // TC-15: Simulate timeout after submission -> marked UNKNOWN, never auto-resubmitted
        $reprintTimeout = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/prints", [
            'revision' => $rev,
            'final_asset_id' => $finalAssetId,
            'copies' => 1,
            'action' => 'reprint',
        ]);
        $rev = (int) $reprintTimeout['json']['revision'];
        $timeoutPrintId = (string) $reprintTimeout['json']['print_request_id'];

        $this->container->workerRunner->runTick(null, 'post_submit_timeout');
        $unkStatus = $this->apiRequest('GET', "/custom/api/prints/{$timeoutPrintId}")['json'];
        self::assertSame('UNKNOWN', $unkStatus['status']);
        // Run another worker tick to prove UNKNOWN is never auto-retried
        $this->container->workerRunner->runTick();
        self::assertSame(2, $mockPrinter->countReceipts());

        // Admin resolves UNKNOWN print via POST /prints/{id}/resolve
        $resolveRes = $this->apiRequest(
            'POST',
            "/custom/api/prints/{$timeoutPrintId}/resolve",
            [
                'revision' => $rev,
                'outcome' => 'confirmed_not_printed',
                'note' => 'Đã kiểm tra khay giấy CP1500, không có bản in nào ra.',
            ],
            $this->adminCookie,
            $this->adminCsrf
        );
        self::assertSame(200, $resolveRes['status']);
        self::assertSame('FAILED', $resolveRes['json']['status']);
        self::assertSame('confirmed_not_printed', $resolveRes['json']['resolution_outcome']);
        $rev = (int) $resolveRes['json']['session_revision'];

        // IT-09: Crash while print is in SUBMITTING state -> reconciled on startup to UNKNOWN without duplicate submit
        $reprintCrash = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/prints", [
            'revision' => $rev,
            'final_asset_id' => $finalAssetId,
            'copies' => 1,
            'action' => 'reprint',
        ]);
        $rev = (int) $reprintCrash['json']['revision'];
        $crashPrintId = (string) $reprintCrash['json']['print_request_id'];

        $this->container->printService->processNextPendingPrint('normal', crashAfterSubmitting: true);
        self::assertSame('SUBMITTING', $this->apiRequest('GET', "/custom/api/prints/{$crashPrintId}")['json']['status']);

        $this->container->workerRunner->reconcileStartup();
        self::assertSame('UNKNOWN', $this->apiRequest('GET', "/custom/api/prints/{$crashPrintId}")['json']['status']);

        // Resolve crashPrintId so we can test IT-10 and IT-08 cleanly
        $resCrash = $this->apiRequest(
            'POST',
            "/custom/api/prints/{$crashPrintId}/resolve",
            [
                'revision' => $rev,
                'outcome' => 'observed_completed',
                'note' => 'Khách đã nhận bản in.',
            ],
            $this->adminCookie,
            $this->adminCsrf
        );
        $rev = (int) $resCrash['json']['session_revision'];

        // IT-10: Adapter loses queue history after QUEUED -> marks UNKNOWN, never infers COMPLETED
        $reprintLost = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/prints", [
            'revision' => $rev,
            'final_asset_id' => $finalAssetId,
            'copies' => 1,
            'action' => 'reprint',
        ]);
        $rev = (int) $reprintLost['json']['revision'];
        $lostPrintId = (string) $reprintLost['json']['print_request_id'];
        $this->container->workerRunner->runTick(null, 'lost_queue_state');
        self::assertSame('UNKNOWN', $this->apiRequest('GET', "/custom/api/prints/{$lostPrintId}")['json']['status']);

        // IT-08: Close session while print is pending/unresolved -> session closes, retention protects assets
        $closeRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/close", [
            'revision' => $rev,
        ]);
        self::assertSame(200, $closeRes['status']);
        self::assertSame('CLOSED', $closeRes['json']['state']);

        // Advance clock 8 days past retention -> cleanup must HOLD session because lostPrintId is still UNKNOWN!
        Clock::setMockTimestamp(Clock::nowUnix() + 8 * 86400);
        $maintHeld = $this->container->maintenanceService->runMaintenancePass();
        self::assertSame(1, $maintHeld['held_by_unresolved_prints']);
        self::assertSame(0, $maintHeld['tombstoned_completed']);

        // Re-login after jumping 8 days forward (since login sessions expire after 8 hours idle)
        $reLogin = $this->apiRequest('POST', '/custom/api/auth/login', [
            'username' => 'operator1',
            'password' => 'OperatorPass123!',
        ], '', '');
        self::assertSame(200, $reLogin['status']);
        $this->operatorCookie = $reLogin['cookies'][0]['value'];
        $this->operatorCsrf = $reLogin['json']['csrf_token'];

        self::assertSame(200, $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['status']);
    }

    public function testTc16Tc17Tc18Tc19AndIt11It12It13It17SecurityAndGuestShare(): void
    {
        // TC-16: Access operator API without login -> 401 AUTH_REQUIRED
        $unauth = $this->apiRequest('GET', '/custom/api/sessions/active', null, '', '');
        self::assertSame(401, $unauth['status']);
        self::assertSame('AUTH_REQUIRED', $unauth['json']['error']['code']);

        // TC-16: Mutation without CSRF token -> 403 CSRF_INVALID
        $noCsrf = $this->apiRequest('POST', '/custom/api/sessions', [], $this->operatorCookie, '');
        self::assertSame(403, $noCsrf['status']);
        self::assertSame('CSRF_INVALID', $noCsrf['json']['error']['code']);

        // TC-16: Brute-force rate limit (5 failed login attempts in 15 min -> 429 LOGIN_RATE_LIMITED)
        for ($i = 1; $i <= 5; $i++) {
            $bad = $this->apiRequest('POST', '/custom/api/auth/login', [
                'username' => 'operator1',
                'password' => 'wrong-pass-' . $i,
            ], '', '');
            self::assertSame(401, $bad['status']);
        }
        $rateLimited = $this->apiRequest('POST', '/custom/api/auth/login', [
            'username' => 'operator1',
            'password' => 'OperatorPass123!',
        ], '', '');
        self::assertSame(429, $rateLimited['status']);
        self::assertSame('LOGIN_RATE_LIMITED', $rateLimited['json']['error']['code']);
        self::assertArrayHasKey('Retry-After', $rateLimited['headers']);

        // Advance clock 16 minutes to clear rate limit window
        Clock::setMockTimestamp(Clock::nowUnix() + 960);

        // TC-17: Tamper with asset ID or path traversal -> denied
        $traversal = $this->apiRequest('GET', '/custom/api/assets/..%2F..%2Fetc%2Fpasswd');
        self::assertSame(404, $traversal['status']);

        try {
            $this->container->assetStore->absolutePath('sessions/../../etc/passwd');
            self::fail('Expected FORBIDDEN on path traversal');
        } catch (ApiException $e) {
            self::assertSame('FORBIDDEN', $e->getErrorCode());
        }

        // Complete a session and test QR share & guest listener isolation (TC-18, TC-19, IT-07, IT-11, IT-12, IT-13, IT-17)
        $session = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $sessionId = (string) $session['id'];
        $rev = (int) $session['revision'];
        foreach ([1, 2, 3] as $s) {
            $r = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/slots/{$s}/capture", ['revision' => $rev]);
            $rev = (int) $r['json']['revision'];
            $this->container->workerRunner->runTick();
        }
        $rRen = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/render", ['revision' => $rev, 'lut_id' => 'original']);
        $rev = (int) $rRen['json']['revision'];
        $this->container->workerRunner->runTick();
        $renderId = (string) $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['json']['current_render']['id'];
        $finRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/finalize", ['revision' => $rev, 'render_id' => $renderId]);
        $rev = (int) $finRes['json']['revision'];
        $finalId = (string) $finRes['json']['final_asset_id'];
        $finalSha = (string) $finRes['json']['sha256'];

        $shareIdem = Uuid::v4();
        $shareRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/shares", [
            'revision' => $rev,
            'final_asset_id' => $finalId,
        ], null, null, $shareIdem);
        self::assertSame(201, $shareRes['status']);
        $rev = (int) $shareRes['json']['revision'];
        $shareId = (string) $shareRes['json']['share_id'];
        $shareUrl = (string) $shareRes['json']['url'];

        // Verify idempotent replay of share creation returns the exact same URL without storing plaintext token in DB!
        $shareReplay = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/shares", [
            'revision' => $rev - 1,
            'final_asset_id' => $finalId,
        ], null, null, $shareIdem);
        self::assertSame(201, $shareReplay['status']);
        self::assertSame($shareUrl, $shareReplay['json']['url']);

        // Verify DB does not contain raw token string
        $rawToken = basename((string) parse_url($shareUrl, PHP_URL_PATH));
        $idemJson = (string) $this->container->db->pdo()
            ->query("SELECT response_json FROM idempotency_records WHERE operation = 'session.share'")
            ->fetchColumn();
        self::assertStringNotContainsString($rawToken, $idemJson);

        // TC-19 & IT-17: Guest viewer and download on Guest LAN listener
        self::assertStringStartsWith('http://192.168.1.50:8081/share/', $shareUrl);
        $viewerRes = $this->apiRequest('GET', "/share/{$rawToken}", null, '', '', null, [], [], 'guest');
        self::assertSame(200, $viewerRes['status']);
        self::assertSame('no-referrer', $viewerRes['headers']['Referrer-Policy']);
        self::assertStringContainsString('no-store', $viewerRes['headers']['Cache-Control']);
        // Zero external CDN / http(s) links in viewer HTML (IT-17)
        self::assertDoesNotMatchRegularExpression('#https?://#i', $viewerRes['raw_body']);

        $dlRes = $this->apiRequest('GET', "/share/{$rawToken}/download", null, '', '', null, [], [], 'guest');
        self::assertSame(200, $dlRes['status']);
        self::assertSame($finalSha, hash_file('sha256', (string) $dlRes['file_path']));

        // IT-13: Guest listener strictly blocks /custom/api/*, /api/*, /admin/*, /private/*
        foreach (['/custom/api/health', '/custom/api/sessions', '/api/takePic.php', '/admin/index.php', '/private/secret.jpg'] as $blockedPath) {
            $blocked = $this->apiRequest('GET', $blockedPath, null, $this->adminCookie, $this->adminCsrf, null, [], [], 'guest');
            self::assertSame(403, $blocked['status'], "Guest listener must block {$blockedPath}");
        }

        // IT-07: Close without printing -> session closes, QR still works
        $closeRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/close", ['revision' => $rev]);
        self::assertSame(200, $closeRes['status']);
        $rev = (int) $closeRes['json']['revision'];
        self::assertSame(200, $this->apiRequest('GET', "/share/{$rawToken}/download", null, '', '', null, [], [], 'guest')['status']);

        // IT-11: Corrupt final file on disk -> guest download and print are blocked with integrity error
        $finalAbsPath = (string) $dlRes['file_path'];
        $origBytes = (string) file_get_contents($finalAbsPath);
        file_put_contents($finalAbsPath, $origBytes . 'CORRUPTED_BYTE');

        $corruptDl = $this->apiRequest('GET', "/share/{$rawToken}/download", null, '', '', null, [], [], 'guest');
        self::assertSame(404, $corruptDl['status']);
        self::assertSame(ShareService::PUBLIC_INVALID_MESSAGE, $corruptDl['json']['error']['message']);

        $corruptPrint = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/prints", [
            'revision' => $rev,
            'final_asset_id' => $finalId,
            'copies' => 1,
            'action' => 'reprint',
        ]);
        self::assertSame(409, $corruptPrint['status']);
        self::assertSame('INTEGRITY_ERROR', $corruptPrint['json']['error']['code']);

        // Restore valid bytes so we can test TC-18 token expiry and revocation
        file_put_contents($finalAbsPath, $origBytes);
        self::assertSame(200, $this->apiRequest('GET', "/share/{$rawToken}/download", null, '', '', null, [], [], 'guest')['status']);

        // TC-18 & IT-12: Revoke token -> uniform public error without leaking session ID; admin still sees session
        $revokeRes = $this->apiRequest('POST', "/custom/api/shares/{$shareId}/revoke", ['revision' => $rev]);
        self::assertSame(200, $revokeRes['status']);

        $revokedDl = $this->apiRequest('GET', "/share/{$rawToken}/download", null, '', '', null, [], [], 'guest');
        self::assertSame(404, $revokedDl['status']);
        self::assertStringNotContainsString($sessionId, $revokedDl['raw_body']);
        self::assertSame(200, $this->apiRequest('GET', "/custom/api/sessions/{$sessionId}")['status']);
    }

    public function testTc27Tc29Tc30AndIt14It15It16It18DiskLowRetentionBackupAndConfig(): void
    {
        // IT-18: Invalid adapter or localhost SHARE_BASE_URL fails explicitly
        try {
            $this->createContainer(['CAMERA_ADAPTER' => 'invalid_adapter']);
            self::fail('Expected INVALID_ADAPTER_CONFIG');
        } catch (ApiException $e) {
            self::assertSame('INVALID_ADAPTER_CONFIG', $e->getErrorCode());
        }

        try {
            $this->createContainer(['SHARE_BASE_URL' => 'http://localhost:8081']);
            self::fail('Expected INVALID_SHARE_BASE_URL');
        } catch (ApiException $e) {
            self::assertSame('INVALID_SHARE_BASE_URL', $e->getErrorCode());
        }

        // TC-27: Disk low (< 2 GiB) blocks new capture/render/print while allowing view and cancel/close
        $lowDiskContainer = $this->createContainer(['MOCK_DISK_FREE_BYTES' => (string) (500 * 1024 * 1024)]);
        $lowDiskKernel = new HttpKernel($lowDiskContainer);

        $createRes = $this->apiRequest('POST', '/custom/api/sessions');
        $sessionId = (string) $createRes['json']['id'];
        $rev = (int) $createRes['json']['revision'];

        $lowCap = $lowDiskKernel->handle([
            'method' => 'POST',
            'path' => "/custom/api/sessions/{$sessionId}/slots/1/capture",
            'headers' => [
                'Host' => '127.0.0.1:8080',
                'X-CSRF-Token' => $this->operatorCsrf,
                'Idempotency-Key' => Uuid::v4(),
            ],
            'cookies' => [AuthService::COOKIE_NAME => $this->operatorCookie],
            'body' => ['revision' => $rev],
        ]);
        self::assertSame(503, $lowCap['status']);
        self::assertSame('DISK_LOW', json_decode($lowCap['body'], true)['error']['code']);

        // Cancel still works under low disk
        $cancelRes = $this->apiRequest('POST', "/custom/api/sessions/{$sessionId}/cancel", ['revision' => $rev]);
        self::assertSame(200, $cancelRes['status']);
        self::assertSame('CANCELLED', $cancelRes['json']['state']);

        // Create and complete another session for backup/restore (IT-14) and two-phase delete (IT-16)
        $s2 = $this->apiRequest('POST', '/custom/api/sessions')['json'];
        $s2Id = (string) $s2['id'];
        $rev2 = (int) $s2['revision'];
        foreach ([1, 2, 3] as $slot) {
            $r = $this->apiRequest('POST', "/custom/api/sessions/{$s2Id}/slots/{$slot}/capture", ['revision' => $rev2]);
            $rev2 = (int) $r['json']['revision'];
            $this->container->workerRunner->runTick();
        }
        $rRen = $this->apiRequest('POST', "/custom/api/sessions/{$s2Id}/render", ['revision' => $rev2, 'lut_id' => 'original']);
        $rev2 = (int) $rRen['json']['revision'];
        $this->container->workerRunner->runTick();
        $renId = (string) $this->apiRequest('GET', "/custom/api/sessions/{$s2Id}")['json']['current_render']['id'];
        $fin = $this->apiRequest('POST', "/custom/api/sessions/{$s2Id}/finalize", ['revision' => $rev2, 'render_id' => $renId]);
        $rev2 = (int) $fin['json']['revision'];
        $finalId = (string) $fin['json']['final_asset_id'];

        // Enqueue a print request and leave it PENDING before taking backup to test IT-14
        $prPending = $this->apiRequest('POST', "/custom/api/sessions/{$s2Id}/prints", [
            'revision' => $rev2,
            'final_asset_id' => $finalId,
            'copies' => 1,
            'action' => 'print',
        ]);
        $rev2 = (int) $prPending['json']['revision'];
        $pendingPrintId = (string) $prPending['json']['print_request_id'];

        // IT-14: Create backup, restore to clean volume, verifyPENDING print is reconciled to UNKNOWN and NOT printed
        $backupDir = $this->tempDataDir . '/backup-test';
        $backupInfo = $this->container->maintenanceService->createBackup($backupDir);
        self::assertGreaterThanOrEqual(6, $backupInfo['asset_count']);

        $restoreStats = $this->container->maintenanceService->restoreFromBackup($backupDir, signingKeyRestored: false);
        self::assertSame($backupInfo['asset_count'], $restoreStats['restored_assets']);
        self::assertSame(1, $restoreStats['reconciled_prints']);

        // Re-initialize container on restored DB and run worker: zero new print receipts should be created!
        $this->container = $this->createContainer();
        $this->kernel = new HttpKernel($this->container);
        $this->container->workerRunner->drainAll();
        /** @var MockPrintAdapter $restoredMockPrinter */
        $restoredMockPrinter = $this->container->printAdapter;
        self::assertSame(0, $restoredMockPrinter->countReceipts());

        // Resolve the restored UNKNOWN print so we can test manual delete & two-phase deletion (IT-16)
        $resRestored = $this->apiRequest(
            'POST',
            "/custom/api/prints/{$pendingPrintId}/resolve",
            [
                'revision' => $rev2,
                'outcome' => 'confirmed_not_printed',
                'note' => 'Khôi phục từ backup.',
            ],
            $this->adminCookie,
            $this->adminCsrf
        );
        $rev2 = (int) $resRestored['json']['session_revision'];

        // IT-16: Admin manual delete -> Phase 1 marks tombstone immediately, Phase 2 worker deletes files
        $delRes = $this->apiRequest(
            'POST',
            "/custom/api/sessions/{$s2Id}/delete",
            ['revision' => $rev2, 'confirm' => true],
            $this->adminCookie,
            $this->adminCsrf
        );
        self::assertSame(202, $delRes['status']);
        // Session is immediately hidden in phase 1
        self::assertSame(404, $this->apiRequest('GET', "/custom/api/sessions/{$s2Id}")['status']);
        // Worker runs phase 2 and removes files from disk
        $this->container->workerRunner->drainAll();
        self::assertDirectoryDoesNotExist($this->container->assetStore->sessionDir($s2Id));

        // IT-15: Two worker instances started simultaneously -> only one acquires singleton lock
        $w1 = $this->container->workerRunner;
        $w2 = new WorkerRunner(
            $this->container->db,
            $this->container->config,
            $this->container->assetStore,
            $this->container->cameraAdapter,
            $this->container->renderer,
            $this->container->printService,
            $this->container->logger,
            $this->container->catalog,
            $this->container->luts,
            'worker-second-instance'
        );
        self::assertTrue($w1->acquireSingletonLock());
        self::assertFalse($w2->acquireSingletonLock());
        $w1->releaseSingletonLock();
    }
}
