<?php

declare(strict_types=1);

use Photobooth\Operator\Container;
use Photobooth\Operator\Http\HttpKernel;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Guest LAN Front Controller (Port 8081)
 * Strictly enforces listener_mode = 'guest' so only GET /share/{token} and
 * GET /share/{token}/download are served. All other paths return 403 FORBIDDEN.
 */
$container = Container::boot();
$kernel = new HttpKernel($container);

$headers = [];
if (function_exists('getallheaders')) {
    foreach ((array) getallheaders() as $k => $v) {
        $headers[(string) $k] = (string) $v;
    }
} else {
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $name = str_replace('_', '-', strtolower(substr($k, 5)));
            $headers[$name] = (string) $v;
        }
    }
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);

$response = $kernel->handle([
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    'path' => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
    'query' => $_GET,
    'headers' => $headers,
    'cookies' => $_COOKIE,
    'body' => null,
    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    'listener_mode' => 'guest',
    'https' => $isHttps,
]);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header("{$name}: {$value}");
}

if ($response['file_path'] !== null && is_file($response['file_path'])) {
    readfile($response['file_path']);
    exit;
}

echo $response['body'];

