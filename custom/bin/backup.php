#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$options = getopt('', ['target:']);
$target = (string) ($options['target'] ?? ($argv[1] ?? ''));

if ($target === '') {
    fwrite(STDERR, "Usage: php custom/bin/backup.php --target=/path/to/backup-dir\n");
    exit(2);
}

$container = new Container();
try {
    $res = $container->maintenanceService->createBackup($target);
    echo json_encode(['status' => 'ok', 'backup' => $res], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

