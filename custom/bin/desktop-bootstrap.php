<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

try {
    $input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    $container = Container::boot([], false);
    $status = $container->migrations->status();
    if ($status['pending_count'] > 0 && $status['applied_count'] > 0) {
        $directory = $container->config->dataDir . '/backups';
        if (!is_dir($directory)) { mkdir($directory, 0750, true); }
        $target = $directory . '/before-migration-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        $container->db->pdo()->exec('VACUUM INTO ' . $container->db->pdo()->quote($target));
    }
    $container->migrations->migrate();
    if ((int) $container->db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $username = (string) ($input['username'] ?? '');
        $password = (string) ($input['password'] ?? '');
        if ($username === '' || strlen($password) < 12) { throw new RuntimeException('Lần đầu cần tên Admin và mật khẩu ít nhất 12 ký tự.'); }
        $container->authService->createUser($username, $password, 'admin');
    }
    echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . ($error->getPrevious() ? ' (' . $error->getPrevious()->getMessage() . ')' : '') . PHP_EOL);
    exit(1);
}
