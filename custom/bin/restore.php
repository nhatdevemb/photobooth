#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$options = getopt('', ['source:', 'signing-key-restored::']);
$source = (string) ($options['source'] ?? ($argv[1] ?? ''));
$keyRestored = !isset($options['signing-key-restored']) || $options['signing-key-restored'] !== 'false';

if ($source === '') {
    fwrite(STDERR, "Usage: php custom/bin/restore.php --source=/path/to/backup-dir [--signing-key-restored=true|false]\n");
    exit(2);
}

$container = new Container([], autoMigrate: false);
try {
    $res = $container->maintenanceService->restoreFromBackup($source, $keyRestored);
    echo json_encode(['status' => 'ok', 'restore' => $res], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

