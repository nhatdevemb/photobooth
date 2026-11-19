#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$app = new Container();
$dir = $app->config->dataDir . '/beauty';
if (!is_dir($dir)) {
    @mkdir($dir, 0750, true);
}
$worker = fopen($dir . '/worker.lock', 'c');
if ($worker === false || !flock($worker, LOCK_EX | LOCK_NB)) {
    exit(1);
}
$app->beautyService->recoverDiagnostic();

$lastPreparation = null;
$cleanAt = 0;
while (true) {
    try {
        $pending = $app->beautyService->pendingPreparation();
        if ($pending === null) {
            $lastPreparation = null;
        } elseif ($pending !== $lastPreparation) {
            $ran = $app->renderer->withBeautyLock(function () use ($app, $pending, &$lastPreparation): bool {
                $app->assetStore->assertDiskSpaceAvailable();
                $lastPreparation = $pending;
                $result = $app->renderer->runBeautyTask($pending);
                $app->logger->info('beauty_background', 'OK', [
                    'session_id' => $pending['beauty_session_id'],
                    'prepared' => $result['prepared'] ?? false,
                    'error' => $result['error'] ?? $result['message'] ?? null,
                ]);
                return true;
            }, false);
            if ($ran) {
                continue;
            }
        }
        $app->beautyService->runDiagnostic();
        if (time() >= $cleanAt) {
            $app->beautyService->cleanClosedCaches();
            $cleanAt = time() + 5;
        }
    } catch (\Throwable $e) {
        $app->logger->warning('beauty_background', 'BEAUTY_WORKER_ERROR', ['message' => $e->getMessage()]);
        usleep(1_000_000);
    }
    usleep(250_000);
}
