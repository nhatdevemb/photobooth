#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$container = new Container([], autoMigrate: false);
$applied = $container->migrations->migrate();
$status = $container->migrations->status();

echo json_encode([
    'status' => $status['ok'] ? 'ok' : 'error',
    'newly_applied' => $applied,
    'applied_count' => $status['applied_count'],
    'versions' => $status['versions'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

