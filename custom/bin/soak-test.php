#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;
use Photobooth\Operator\Http\HttpKernel;
use Photobooth\Operator\Printer\MockPrintAdapter;
use Photobooth\Operator\Support\Uuid;

$tStart = microtime(true);
$customRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$repoRoot = dirname($customRoot);
$tmpBase = sys_get_temp_dir() . '/pb-soak-' . getmypid() . '-' . bin2hex(random_bytes(3));
$dataDir = $tmpBase . '/data';
$backupDir = $tmpBase . '/backup-snapshot';
$restoreDataDir = $tmpBase . '/restored-data';

@mkdir($dataDir, 0750, true);
@mkdir($restoreDataDir, 0750, true);

$envOverrides = [
    'APP_ENV' => 'testing',
    'DATA_DIR' => $dataDir,
    'DATABASE_PATH' => $dataDir . '/db/photobooth.sqlite',
    'CAMERA_ADAPTER' => 'mock',
    'PRINTER_ADAPTER' => 'mock',
    'MOCK_CAPTURE_DELAY_MS' => '0',
    'MOCK_COUNTDOWN_DELAY_MS' => '0',
    'DISK_LOW_BYTES' => '1048576',
    'SHARE_SIGNING_KEY' => 'soak-test-signing-key-0123456789abcdef',
];

$container = new Container($envOverrides, autoMigrate: true);
$container->authService->createUser('operator1', 'OperatorPass123!', 'operator');
$container->authService->createUser('admin', 'AdminPass123!', 'admin');

$kernel = new HttpKernel($container);

// Authenticate operator1
$loginResp = $kernel->handle([
    'method' => 'POST',
    'path' => '/custom/api/auth/login',
    'headers' => ['Origin' => 'http://127.0.0.1:8080', 'Host' => '127.0.0.1:8080'],
    'body' => ['username' => 'operator1', 'password' => 'OperatorPass123!'],
]);
if ($loginResp['status'] !== 200) {
    fwrite(STDERR, "Login failed in soak test\n");
    exit(1);
}
$loginBody = json_decode($loginResp['body'], true);
$csrf = $loginBody['csrf_token'];
$cookieVal = $loginResp['cookies'][0]['value'];

$api = function (string $method, string $path, ?array $body = null) use ($kernel, $csrf, $cookieVal): array {
    $resp = $kernel->handle([
        'method' => $method,
        'path' => $path,
        'headers' => [
            'Origin' => 'http://127.0.0.1:8080',
            'Host' => '127.0.0.1:8080',
            'X-CSRF-Token' => $csrf,
            'Idempotency-Key' => Uuid::v4(),
        ],
        'cookies' => ['pb_operator_sid' => $cookieVal],
        'body' => $body,
    ]);
    if ($resp['status'] >= 400) {
        throw new RuntimeException("API {$method} {$path} failed ({$resp['status']}): {$resp['body']}");
    }
    return json_decode($resp['body'], true) ?: [];
};

$presets = ['original', 'korean-soft', 'film-400', 'vintage-fade'];
$adminLogin = $kernel->handle(['method'=>'POST', 'path'=>'/custom/api/auth/login', 'body'=>['username'=>'admin','password'=>'AdminPass123!']]);
$adminAuth = json_decode($adminLogin['body'], true);
$adminCookie = $adminLogin['cookies'][0]['value'];
$adminCall = function (string $path, array $body) use ($kernel, $adminAuth, $adminCookie): array {
    $response = $kernel->handle(['method'=>'POST', 'path'=>$path, 'headers'=>['X-CSRF-Token'=>$adminAuth['csrf_token'], 'Idempotency-Key'=>Uuid::v4(), 'Content-Type'=>str_ends_with($path, '/import') ? 'multipart/form-data' : 'application/json'], 'cookies'=>['pb_operator_sid'=>$adminCookie], 'body'=>$body]);
    if ($response['status'] >= 400) {
        throw new RuntimeException('Soak LUT setup failed: ' . $response['body']);
    }
    return json_decode($response['body'], true);
};
foreach (['korean-soft'=>25, 'film-400'=>65, 'vintage-fade'=>90] as $preset => $intensity) {
    $draft = $adminCall('/custom/api/admin/luts/import', ['file'=>['name'=>$preset . '.cube', 'error'=>0, 'content'=>"# soak imported LUT\n" . file_get_contents($customRoot . '/presets/' . $preset . '.cube')]]);
    $path = '/custom/api/admin/luts/' . $draft['id'] . '/1.0.0';
    $draft = $adminCall($path . '/draft', ['revision'=>1, 'name'=>'Soak ' . $preset, 'intensity'=>$intensity]);
    $active = $adminCall($path . '/activate', ['revision'=>$draft['revision'], 'preview_sha256'=>$draft['preview_sha256']]);
    $presets[] = $active['id'];
}
$importedLutSessions = 0;
$retakeSessionsCount = 0;
$reprintSessionsCount = 0;
$verifiedQrDownloads = 0;
$expectedSlotPhotos = 0;
$layouts = ['single', 'strip-three', 'postcard-three', 'grid-four'];
$styles = ['minimal', 'party', 'floral', 'cute'];

for ($i = 1; $i <= 30; $i++) {
    $s = $api('POST', '/custom/api/sessions', []);
    $sid = $s['id'];
    $rev = $s['revision'];

    $layout = $layouts[($i - 1) % 4];
    $style = $styles[(int) floor(($i - 1) / 4) % 4];
    $template = $style === 'minimal' && $layout === 'postcard-three' ? 'vertical-three-v1' : $style . '-' . $layout;
    $selectedLut = $presets[($i - 1) % count($presets)];
    if (str_starts_with($selectedLut, 'lut-')) {
        $importedLutSessions++;
    }
    $s = $api('POST', "/custom/api/sessions/{$sid}/configuration", ['revision'=>$rev, 'layout_id'=>$layout, 'template_id'=>$template, 'lut_id'=>$selectedLut]);
    $rev = $s['revision'];
    $expectedSlotPhotos += $s['capture_count'];
    $captureCount = $s['capture_count'];
    for ($slot = 1; $slot <= $captureCount; $slot++) {
        $api('POST', "/custom/api/sessions/{$sid}/slots/{$slot}/capture", ['revision' => $rev]);
        $container->workerRunner->drainAll();
        $s = $api('GET', "/custom/api/sessions/{$sid}");
        $rev = $s['revision'];
    }

    // Perform retake on 12 sessions (>= 10 required by TC-26)
    if ($i <= 12) {
        $retakeSlot = (($i - 1) % $captureCount) + 1;
        $api('POST', "/custom/api/sessions/{$sid}/slots/{$retakeSlot}/retake", ['revision' => $rev]);
        $container->workerRunner->drainAll();
        $s = $api('GET', "/custom/api/sessions/{$sid}");
        $rev = $s['revision'];
        $retakeSessionsCount++;
    }

    // Render with rotating LUT preset
    $lutId = $presets[($i - 1) % count($presets)];
    $api('POST', "/custom/api/sessions/{$sid}/render", [
        'revision' => $rev,
        'lut_id' => $lutId,
        'template_id' => $template,
    ]);
    $container->workerRunner->drainAll();
    $s = $api('GET', "/custom/api/sessions/{$sid}");
    $rev = $s['revision'];
    $renderId = $s['current_render']['id'];

    // Finalize
    $fin = $api('POST', "/custom/api/sessions/{$sid}/finalize", [
        'revision' => $rev,
        'render_id' => $renderId,
    ]);
    $rev = $fin['revision'];
    $finalAssetId = $fin['final_asset_id'];

    // Issue QR Share and verify Guest LAN download
    $share = $api('POST', "/custom/api/sessions/{$sid}/shares", [
        'revision' => $rev,
        'final_asset_id' => $finalAssetId,
    ]);
    $rev = $share['revision'];
    $dlPath = (string) parse_url($share['download_url'], PHP_URL_PATH);

    $dlResp = $kernel->handle([
        'method' => 'GET',
        'path' => $dlPath,
        'listener_mode' => 'guest',
    ]);
    if ($dlResp['status'] === 200 && $dlResp['file_path'] !== null && is_file($dlResp['file_path'])) {
        $verifiedQrDownloads++;
    }

    // Initial print (1 copy)
    $api('POST', "/custom/api/sessions/{$sid}/prints", [
        'revision' => $rev,
        'final_asset_id' => $finalAssetId,
        'copies' => 1,
        'action' => 'print',
    ]);
    $container->workerRunner->drainAll();
    $s = $api('GET', "/custom/api/sessions/{$sid}");
    $rev = $s['revision'];

    // Reprint on 5 sessions (sessions 1..5)
    if ($i <= 5) {
        $api('POST', "/custom/api/sessions/{$sid}/prints", [
            'revision' => $rev,
            'final_asset_id' => $finalAssetId,
            'copies' => 1,
            'action' => 'reprint',
        ]);
        $container->workerRunner->drainAll();
        $s = $api('GET', "/custom/api/sessions/{$sid}");
        $rev = $s['revision'];
        $reprintSessionsCount++;
    }

    // Close session
    $api('POST', "/custom/api/sessions/{$sid}/close", ['revision' => $rev]);
}

// Verify TC-26 invariants in SQLite and filesystem
$pdo = $container->db->pdo();
$closedSessions = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE state = 'CLOSED'")->fetchColumn();
$activeSlotPhotos = (int) $pdo->query("SELECT COUNT(*) FROM session_slots WHERE current_photo_id IS NOT NULL")->fetchColumn();
$totalSlotPhotos = (int) $pdo->query("SELECT COUNT(*) FROM photos")->fetchColumn();
$printedRows = (int) $pdo->query("SELECT COUNT(*) FROM print_requests WHERE status = 'COMPLETED'")->fetchColumn();

$finalRows = $pdo->query("SELECT path, sha256, width, height FROM final_assets")->fetchAll();

$corruptFinals = 0;
foreach ($finalRows as $fr) {
    $abs = $container->assetStore->absolutePath((string) $fr['path']);
    if (
        !is_file($abs)
        || hash_file('sha256', $abs) !== $fr['sha256']
        || (int) $fr['width'] !== 1181
        || (int) $fr['height'] !== 1748
    ) {
        $corruptFinals++;
    }
}

$receiptCount = $container->printAdapter instanceof MockPrintAdapter
    ? $container->printAdapter->countReceipts()
    : 0;

// TC-25 Rehearsal: Backup & Restore verification on all 30 sessions
$backupRes = $container->maintenanceService->createBackup($backupDir);

$restoreEnv = array_merge($envOverrides, [
    'DATA_DIR' => $restoreDataDir,
    'DATABASE_PATH' => $restoreDataDir . '/db/photobooth.sqlite',
]);
$restoreContainer = new Container($restoreEnv, autoMigrate: false);
$restoreRes = $restoreContainer->maintenanceService->restoreFromBackup($backupDir, true);

// Open a fresh connection to the restored SQLite file on disk
$verifiedRestoreContainer = new Container($restoreEnv, autoMigrate: false);
$restoredClosedSessions = (int) $verifiedRestoreContainer->db->pdo()->query("SELECT COUNT(*) FROM sessions WHERE state = 'CLOSED'")->fetchColumn();
$restoredImportedLuts = count(array_filter($verifiedRestoreContainer->luts->items(), static fn (array $item): bool => !$item['readonly']));

$durationSec = round(microtime(true) - $tStart, 2);

// Clean up temporary soak directory
$container->assetStore->removeDirectoryRecursive($tmpBase);

$passed = (
    $closedSessions === 30
    && $activeSlotPhotos === $expectedSlotPhotos
    && $retakeSessionsCount === 12
    && count($finalRows) === 30
    && $corruptFinals === 0
    && $printedRows === 35
    && $receiptCount === 35
    && $verifiedQrDownloads === 30
    && ($restoreRes['restored_assets'] ?? 0) === ($backupRes['asset_count'] ?? -1)
    && $restoredClosedSessions === 30
    && $restoredImportedLuts === 3
);

$report = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'test_cases' => ['TC-25', 'TC-26'],
    'mode' => 'mock',
    'duration_seconds' => $durationSec,
    'passed' => $passed,
    'soak_metrics' => [
        'target_sessions' => 30,
        'completed_closed_sessions' => $closedSessions,
        'active_slot_photos' => $activeSlotPhotos,
        'expected_slot_photos' => $expectedSlotPhotos,
        'layouts' => $layouts,
        'imported_lut_sessions' => $importedLutSessions,
        'total_captured_photos_including_superseded' => $totalSlotPhotos,
        'retake_sessions_count' => $retakeSessionsCount,
        'finalized_collages_count' => count($finalRows),
        'corrupt_finals_count' => $corruptFinals,
        'missing_slots_count' => $expectedSlotPhotos - $activeSlotPhotos,
        'initial_prints_count' => 30,
        'reprints_count' => $reprintSessionsCount,
        'total_printed_db_rows' => $printedRows,
        'total_printer_receipt_files' => $receiptCount,
        'verified_guest_qr_downloads' => $verifiedQrDownloads,
    ],
    'backup_restore_rehearsal' => [
        'manifest_assets_count' => $backupRes['asset_count'] ?? 0,
        'verified_restored_assets' => $restoreRes['restored_assets'] ?? 0,
        'restored_closed_sessions' => $restoredClosedSessions,
        'restored_imported_luts' => $restoredImportedLuts,
    ],
];

$reportPath = $repoRoot . '/plan/soak-report.json';
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (!$passed) {
    exit(1);
}
