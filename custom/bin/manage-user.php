#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$action = 'create';
$username = '';
$password = '';
$role = 'operator';

foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['create', 'disable', 'revoke-all-shares'], true)) {
        $action = $arg;
    } elseif (str_starts_with($arg, '--action=')) {
        $action = substr($arg, strlen('--action='));
    } elseif (str_starts_with($arg, '--username=')) {
        $username = substr($arg, strlen('--username='));
    } elseif (str_starts_with($arg, '--password=')) {
        $password = substr($arg, strlen('--password='));
    } elseif (str_starts_with($arg, '--role=')) {
        $role = substr($arg, strlen('--role='));
    }
}

$container = new Container();

try {
    if ($action === 'revoke-all-shares') {
        $revoked = $container->shareService->revokeAllActiveTokens();
        echo json_encode(['status' => 'ok', 'revoked_tokens' => $revoked], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    if ($username === '') {
        fwrite(STDERR, "Usage: php custom/bin/manage-user.php [--action=create|disable|revoke-all-shares] --username=<user> [--password=<pass>] [--role=operator|admin]\n");
        exit(2);
    }

    if ($action === 'disable') {
        $container->authService->disableUser($username);
        echo json_encode(['status' => 'disabled', 'username' => $username], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    if ($password === '') {
        fwrite(STDERR, "Error: --password is required when creating or updating a user.\n");
        exit(2);
    }

    $user = $container->authService->createUser($username, $password, $role);
    echo json_encode(['status' => 'ok', 'user' => $user], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
