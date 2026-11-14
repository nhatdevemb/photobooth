#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$container = new Container();

if (in_array('--revoke-all-tokens', $argv, true)) {
    $revoked = $container->shareService->revokeAllActiveTokens();
    echo json_encode(['status' => 'ok', 'revoked_tokens' => $revoked], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$stats = $container->maintenanceService->runMaintenancePass();
echo json_encode(['status' => 'ok', 'maintenance' => $stats], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

