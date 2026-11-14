#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Photobooth\Operator\Container;
use Photobooth\Operator\Support\Uuid;

/**
 * End-to-End Live HTTP + Background Worker Test Suite (IMP-031)
 * Starts real Operator HTTP server, Guest LAN HTTP server, and Worker daemon,
 * then exercises the full Operator UI & Guest Share workflows over TCP.
 */

$customRoot = realpath(__DIR__ . '/../..') ?: (__DIR__ . '/../..');
$repoRoot = dirname($customRoot);
$tmpBase = sys_get_temp_dir() . '/pb-e2e-' . getmypid() . '-' . bin2hex(random_bytes(3));
$dataDir = $tmpBase . '/data';
@mkdir($dataDir, 0750, true);

// Pick two available loopback TCP ports
function findFreePort(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($sock === false) {
        throw new RuntimeException("Unable to bind ephemeral port: {$errstr}");
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    $parts = explode(':', (string) $name);
    return (int) end($parts);
}

$opPort = findFreePort();
$guestPort = findFreePort();

$env = [
    'PATH' => (string) getenv('PATH'),
    'APP_ENV' => 'testing',
    'DATA_DIR' => $dataDir,
    'DATABASE_PATH' => $dataDir . '/db/photobooth.sqlite',
    'CAMERA_ADAPTER' => 'mock',
    'PRINTER_ADAPTER' => 'mock',
    'MOCK_CAPTURE_DELAY_MS' => '20',
    'MOCK_COUNTDOWN_DELAY_MS' => '20',
    'DISK_LOW_BYTES' => '1048576',
    'OPERATOR_BIND' => "127.0.0.1:{$opPort}",
    'GUEST_BIND' => "127.0.0.1:{$guestPort}",
    'SHARE_BASE_URL' => "http://192.168.4.1:{$guestPort}",
    'TRUSTED_HOSTS' => "127.0.0.1,localhost,127.0.0.1:{$opPort},127.0.0.1:{$guestPort},192.168.4.1:{$guestPort}",
    'SHARE_SIGNING_KEY' => 'e2e-test-signing-key-0123456789abcdef0123456789',
];

$container = new Container($env, autoMigrate: true);
$container->authService->createUser('operator1', 'OperatorPass123!', 'operator');
$container->authService->createUser('admin', 'AdminPass123!', 'admin');

$procs = [];
$cleanup = function () use (&$procs, $container, $tmpBase): void {
    foreach ($procs as $proc) {
        if (is_resource($proc)) {
            $status = proc_get_status($proc);
            if ($status['running']) {
                @posix_kill($status['pid'], SIGTERM);
                usleep(100_000);
                @proc_terminate($proc, 9);
            }
            @proc_close($proc);
        }
    }
    $container->assetStore->removeDirectoryRecursive($tmpBase);
};
register_shutdown_function($cleanup);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $tmpBase . '/stdout.log', 'a'],
    2 => ['file', $tmpBase . '/stderr.log', 'a'],
];

// 1. Start Operator HTTP Server
$opEnv = array_merge($env, ['PHOTOBOOTH_LISTENER_MODE' => 'operator']);
$procs['operator'] = proc_open(
    ['php', '-S', "127.0.0.1:{$opPort}", $customRoot . '/public/index.php'],
    $descriptors,
    $pipesOp,
    $repoRoot,
    $opEnv
);

// 2. Start Guest LAN HTTP Server
$guestEnv = array_merge($env, ['PHOTOBOOTH_LISTENER_MODE' => 'guest']);
$procs['guest'] = proc_open(
    ['php', '-S', "127.0.0.1:{$guestPort}", $customRoot . '/public/guest.php'],
    $descriptors,
    $pipesGuest,
    $repoRoot,
    $guestEnv
);

// 3. Start Background Worker Daemon
$procs['worker'] = proc_open(
    ['php', $customRoot . '/bin/worker.php', '--sleep-ms=80'],
    $descriptors,
    $pipesWorker,
    $repoRoot,
    $opEnv
);

// Wait for servers to listen
for ($i = 0; $i < 30; $i++) {
    $fp1 = @fsockopen('127.0.0.1', $opPort, $e1, $s1, 0.1);
    $fp2 = @fsockopen('127.0.0.1', $guestPort, $e2, $s2, 0.1);
    if ($fp1 && $fp2) {
        fclose($fp1);
        fclose($fp2);
        break;
    }
    if ($fp1) fclose($fp1);
    if ($fp2) fclose($fp2);
    usleep(100_000);
}

function httpCall(
    string $baseUrl,
    string $method,
    string $path,
    ?array $jsonBody = null,
    array $headers = [],
    ?string $cookie = null
): array {
    $ch = curl_init($baseUrl . $path);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $reqHeaders = [];
    foreach ($headers as $k => $v) {
        $reqHeaders[] = "{$k}: {$v}";
    }
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reqHeaders[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    if ($cookie !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    if ($reqHeaders !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reqHeaders);
    }

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    $setCookie = null;
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $rawHeaders, $m) === 1) {
        $setCookie = trim($m[1]);
    }

    return [
        'status' => $status,
        'headers_raw' => $rawHeaders,
        'cookie' => $setCookie,
        'body' => $body,
        'json' => json_decode($body, true),
    ];
}

function assertCond(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException("E2E Assertion Failed: {$message}");
    }
}

$opBase = "http://127.0.0.1:{$opPort}";
$guestBase = "http://127.0.0.1:{$guestPort}";

// Step 1: Verify Vietnamese Operator UI HTML shell & static assets
$uiResp = httpCall($opBase, 'GET', '/');
assertCond($uiResp['status'] === 200, 'GET / on Operator server must return 200');
assertCond(str_contains($uiResp['body'], 'Camera: ảnh MÔ PHỎNG (MOCK)') && str_contains($uiResp['body'], 'Máy in: MÔ PHỎNG (MOCK)'), 'Operator UI must label camera and printer simulation separately');
assertCond(str_contains($uiResp['body'], 'id="login-form"'), 'Operator UI must contain login form');
assertCond(str_contains($uiResp['body'], 'id="modal-print-confirm"'), 'Operator UI must contain print confirmation modal');

$cssResp = httpCall($opBase, 'GET', '/custom/assets/css/operator.css');
assertCond($cssResp['status'] === 200 && str_contains($cssResp['body'], '.sim-banner'), 'CSS asset must load');
assertCond(str_contains($cssResp['body'], 'Be Vietnam Pro'), 'CSS asset must configure Be Vietnam Pro Vietnamese typography');

$fontResp = httpCall($opBase, 'GET', '/custom/assets/fonts/BeVietnamPro-Regular.ttf');
assertCond($fontResp['status'] === 200 && strlen($fontResp['body']) > 50000, 'Self-hosted Be Vietnam Pro font asset must load');

$jsResp = httpCall($opBase, 'GET', '/custom/assets/js/operator.js');
assertCond($jsResp['status'] === 200 && str_contains($jsResp['body'], 'checkAuthOnBoot'), 'JS asset must load');

// Step 2: Login as operator1
$loginResp = httpCall($opBase, 'POST', '/custom/api/auth/login', [
    'username' => 'operator1',
    'password' => 'OperatorPass123!',
], ['Origin' => $opBase]);
assertCond($loginResp['status'] === 200 && $loginResp['cookie'] !== null, 'Operator login must succeed and set cookie; HTTP ' . $loginResp['status'] . ' code=' . ($loginResp['json']['error']['code'] ?? 'none'));
$opCookie = $loginResp['cookie'];
$csrf = (string) $loginResp['json']['csrf_token'];

$mutHeaders = fn (?string $idem = null): array => [
    'Origin' => $opBase,
    'X-CSRF-Token' => $csrf,
    'Idempotency-Key' => $idem ?? Uuid::v4(),
];

// Step 3: Verify Health endpoint reports running Worker
usleep(200_000);
$healthResp = httpCall($opBase, 'GET', '/custom/api/health', null, [], $opCookie);
assertCond($healthResp['status'] === 200, 'Health endpoint must return 200');
assertCond(($healthResp['json']['mode'] ?? '') === 'mock', 'Health must report mock mode');
assertCond(($healthResp['json']['worker']['status'] ?? '') === 'healthy', 'Worker daemon must be healthy');

// Step 4: Verify reload before creating session does not auto-create session (IT-01)
$active0 = httpCall($opBase, 'GET', '/custom/api/sessions/active', null, [], $opCookie);
assertCond($active0['status'] === 200 && $active0['json']['active_session'] === null, 'Active session must be null initially');

// Step 5: Create session and verify page reload resilience (IT-01)
$createResp = httpCall($opBase, 'POST', '/custom/api/sessions', [], $mutHeaders(), $opCookie);
assertCond($createResp['status'] === 201, 'Create session must return 201');
$sessionId = (string) $createResp['json']['id'];
$rev = (int) $createResp['json']['revision'];

$activeReload = httpCall($opBase, 'GET', '/custom/api/sessions/active', null, [], $opCookie);
assertCond(($activeReload['json']['active_session']['id'] ?? '') === $sessionId, 'Reloading active session must return same session');

$configured = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/configuration", ['revision'=>$rev, 'lut_id'=>'korean-soft'], $mutHeaders(), $opCookie);
assertCond($configured['status'] === 200, 'Filter is chosen before the first capture');
$rev = $configured['json']['revision'];

// Step 6: Capture 3 slots via real background worker daemon
for ($slot = 1; $slot <= 3; $slot++) {
    $cap = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/slots/{$slot}/capture", ['revision' => $rev], $mutHeaders(), $opCookie);
    assertCond($cap['status'] === 202, "Capture slot {$slot} must return 202");
    $jobId = (string) $cap['json']['job_id'];

    for ($w = 0; $w < 50; $w++) {
        usleep(100_000);
        $job = httpCall($opBase, 'GET', "/custom/api/jobs/{$jobId}", null, [], $opCookie);
        if (($job['json']['status'] ?? '') === 'SUCCEEDED') {
            break;
        }
    }
    $sCheck = httpCall($opBase, 'GET', "/custom/api/sessions/{$sessionId}", null, [], $opCookie);
    $rev = (int) $sCheck['json']['revision'];
}

// Step 7: Retake slot 2 and verify two-tab stale revision conflict (IT-02)
$staleTry = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/slots/2/retake", ['revision' => 1], $mutHeaders(), $opCookie);
assertCond($staleTry['status'] === 409 && ($staleTry['json']['error']['code'] ?? '') === 'REVISION_CONFLICT', 'Stale revision from 2nd tab must return 409 REVISION_CONFLICT');

$retake = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/slots/2/retake", ['revision' => $rev], $mutHeaders(), $opCookie);
assertCond($retake['status'] === 202, 'Retake slot 2 must return 202');
$retakeJobId = (string) $retake['json']['job_id'];
for ($w = 0; $w < 50; $w++) {
    usleep(100_000);
    $job = httpCall($opBase, 'GET', "/custom/api/jobs/{$retakeJobId}", null, [], $opCookie);
    if (($job['json']['status'] ?? '') === 'SUCCEEDED') {
        break;
    }
}
$sAfterRetake = httpCall($opBase, 'GET', "/custom/api/sessions/{$sessionId}", null, [], $opCookie);
$rev = (int) $sAfterRetake['json']['revision'];
assertCond(($sAfterRetake['json']['slots'][1]['photo']['version'] ?? 0) === 2, 'Slot 2 photo version must be 2 after retake');

// Step 8: Render with Korean Soft LUT + test network retry idempotency (IT-03)
$renderIdem = Uuid::v4();
$render1 = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/render", [
    'revision' => $rev,
    'lut_id' => 'korean-soft',
    'template_id' => 'vertical-three-v1',
], $mutHeaders($renderIdem), $opCookie);
assertCond($render1['status'] === 202, 'Render request must return 202');

$renderReplay = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/render", [
    'revision' => $rev,
    'lut_id' => 'korean-soft',
    'template_id' => 'vertical-three-v1',
], $mutHeaders($renderIdem), $opCookie);
assertCond($renderReplay['status'] === 202 && $renderReplay['json']['job_id'] === $render1['json']['job_id'], 'Idempotent replay must return identical job_id');

$renderJobId = (string) $render1['json']['job_id'];
for ($w = 0; $w < 80; $w++) {
    usleep(100_000);
    $job = httpCall($opBase, 'GET', "/custom/api/jobs/{$renderJobId}", null, [], $opCookie);
    if (($job['json']['status'] ?? '') === 'SUCCEEDED') {
        break;
    }
}

$sReview = httpCall($opBase, 'GET', "/custom/api/sessions/{$sessionId}", null, [], $opCookie);
assertCond(($sReview['json']['state'] ?? '') === 'REVIEW', 'Session state must be REVIEW after render');
$rev = (int) $sReview['json']['revision'];
$renderId = (string) $sReview['json']['current_render']['id'];

// Step 9: Finalize & Print (1 copy)
$fin = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/finalize", [
    'revision' => $rev,
    'render_id' => $renderId,
], $mutHeaders(), $opCookie);
assertCond($fin['status'] === 201 && ($fin['json']['state'] ?? '') === 'FINALIZED', 'Finalize must return 201 FINALIZED');
$rev = (int) $fin['json']['revision'];
$finalAssetId = (string) $fin['json']['final_asset_id'];
$finalSha = (string) $fin['json']['sha256'];

$pr = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/prints", [
    'revision' => $rev,
    'final_asset_id' => $finalAssetId,
    'copies' => 1,
    'action' => 'print',
], $mutHeaders(), $opCookie);
assertCond($pr['status'] === 202, 'Create print request must return 202');
$printId = (string) $pr['json']['print_request_id'];
$rev = (int) $pr['json']['revision'];

for ($w = 0; $w < 50; $w++) {
    usleep(100_000);
    $pCheck = httpCall($opBase, 'GET', "/custom/api/prints/{$printId}", null, [], $opCookie);
    if (($pCheck['json']['status'] ?? '') === 'COMPLETED') {
        break;
    }
}
$pFinal = httpCall($opBase, 'GET', "/custom/api/prints/{$printId}", null, [], $opCookie);
assertCond(($pFinal['json']['status'] ?? '') === 'COMPLETED', 'Print request must reach COMPLETED status via worker');

// Step 10: Issue QR Share token & test Guest LAN Listener isolation (IT-13)
$shareResp = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/shares", [
    'revision' => $rev,
    'final_asset_id' => $finalAssetId,
], $mutHeaders(), $opCookie);
assertCond($shareResp['status'] === 201, 'Create share token must return 201');
$qrUri = (string) ($shareResp['json']['qr_data_uri'] ?? '');
assertCond(
    str_starts_with($qrUri, 'data:image/png;base64,') || str_starts_with($qrUri, 'data:image/svg+xml;base64,'),
    'Offline QR data URI must be returned'
);
$shareId = (string) $shareResp['json']['share_id'];
$rev = (int) $shareResp['json']['revision'];
$sharePath = (string) parse_url((string) $shareResp['json']['url'], PHP_URL_PATH);
$dlPath = (string) parse_url((string) $shareResp['json']['download_url'], PHP_URL_PATH);

$guestViewer = httpCall($guestBase, 'GET', $sharePath);
assertCond($guestViewer['status'] === 200 && str_contains($guestViewer['body'], 'Tải ảnh JPEG chất lượng cao'), 'Guest LAN viewer must return 200 HTML');

$guestDl = httpCall($guestBase, 'GET', $dlPath);
assertCond($guestDl['status'] === 200 && hash('sha256', $guestDl['body']) === $finalSha, 'Guest LAN download must match finalized SHA-256');

$guestBlockedApi = httpCall($guestBase, 'GET', '/custom/api/sessions');
assertCond($guestBlockedApi['status'] === 403, 'Guest LAN listener must block /custom/api/* with 403 FORBIDDEN (IT-13)');

$guestBlockedRoot = httpCall($guestBase, 'GET', '/');
assertCond($guestBlockedRoot['status'] === 403, 'Guest LAN listener must block / with 403 FORBIDDEN (IT-13)');

// Step 11: Revoke QR token and verify Guest LAN immediately returns 404 (TC-17)
$revokeResp = httpCall($opBase, 'POST', "/custom/api/shares/{$shareId}/revoke", ['revision' => $rev], $mutHeaders(), $opCookie);
assertCond($revokeResp['status'] === 200, 'Revoke share token must return 200');
$rev = (int) $revokeResp['json']['revision'];

$guestAfterRevoke = httpCall($guestBase, 'GET', $sharePath);
assertCond($guestAfterRevoke['status'] === 404, 'Revoked share token must return 404 on Guest LAN');

// Step 12: Close session & test Admin session deletion
$closeResp = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/close", ['revision' => $rev], $mutHeaders(), $opCookie);
assertCond($closeResp['status'] === 200 && ($closeResp['json']['state'] ?? '') === 'CLOSED', 'Close session must return 200 CLOSED');

$adminLogin = httpCall($opBase, 'POST', '/custom/api/auth/login', [
    'username' => 'admin',
    'password' => 'AdminPass123!',
], ['Origin' => $opBase]);
$adminCookie = $adminLogin['cookie'];
$adminCsrf = (string) $adminLogin['json']['csrf_token'];

$sBeforeDel = httpCall($opBase, 'GET', "/custom/api/sessions/{$sessionId}", null, [], $adminCookie);
$delRev = (int) $sBeforeDel['json']['revision'];

$delResp = httpCall($opBase, 'POST', "/custom/api/sessions/{$sessionId}/delete", [
    'revision' => $delRev,
    'confirm' => true,
], [
    'Origin' => $opBase,
    'X-CSRF-Token' => $adminCsrf,
    'Idempotency-Key' => Uuid::v4(),
], $adminCookie);
assertCond($delResp['status'] === 202, 'Admin two-phase delete must return 202');

for ($w = 0; $w < 30; $w++) {
    usleep(100_000);
    $checkGone = httpCall($opBase, 'GET', "/custom/api/sessions/{$sessionId}", null, [], $adminCookie);
    if ($checkGone['status'] === 404) {
        break;
    }
}
$checkGone = httpCall($opBase, 'GET', "/custom/api/sessions/{$sessionId}", null, [], $adminCookie);
assertCond($checkGone['status'] === 404, 'Deleted session must return 404 after worker purge');

echo json_encode([
    'e2e_status' => 'PASSED',
    'operator_port' => $opPort,
    'guest_port' => $guestPort,
    'verified_flows' => [
        'operator_ui_html_and_static_assets',
        'auth_login_and_csrf',
        'worker_daemon_health_check',
        'reload_without_duplicate_session_IT01',
        '3_slot_capture_and_retake',
        'two_tab_stale_revision_conflict_IT02',
        'network_retry_idempotent_replay_IT03',
        'lut_render_finalize_and_print_1_copy',
        'offline_qr_generation_and_guest_lan_isolation_IT13',
        'qr_revocation_TC17_and_admin_two_phase_delete',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
