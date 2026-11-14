#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$once = in_array('--once', $argv, true);
$drain = in_array('--drain', $argv, true);

$container = new Container();
$worker = $container->workerRunner;

if (!$worker->acquireSingletonLock()) {
    fwrite(STDERR, "[worker] Another worker instance is already holding the singleton lock.\n");
    exit(1);
}

$startupStats = $worker->reconcileStartup();
$container->logger->info('worker_started', 'OK', $startupStats);

if ($once) {
    $res = $worker->runTick();
    echo json_encode($res) . PHP_EOL;
    exit(0);
}

if ($drain) {
    $worker->drainAll();
    echo json_encode(['status' => 'drained']) . PHP_EOL;
    exit(0);
}

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

while ($running) {
    $tick = $worker->runTick();
    if ($tick['job_processed'] === null && $tick['print_submitted'] === null) {
        usleep(150_000);
    }
}

$worker->updateHeartbeat(null, 'STOPPED');
$worker->releaseSingletonLock();

