#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Photobooth\Operator\Container;

$container = new Container();
$customRoot = $container->config->customRoot;
$fixturesDir = $customRoot . '/tests/fixtures';
$presetsMap = $container->config->getPresetsMap();
$tmpDir = sys_get_temp_dir() . '/pb-benchmark-' . getmypid();
@mkdir($tmpDir, 0750, true);

$results = [];
$allWallTimes = [];

foreach ($presetsMap as $lutId => $preset) {
    $runs = [];
    $lastRes = null;
    for ($i = 1; $i <= 2; $i++) {
        $manifest = [
            'photos' => [
                ['slot' => 1, 'path' => $fixturesDir . '/slot-1-v1.jpg'],
                ['slot' => 2, 'path' => $fixturesDir . '/slot-2-v1.jpg'],
                ['slot' => 3, 'path' => $fixturesDir . '/slot-3-v1.jpg'],
            ],
            'lut_id' => $lutId,
            'lut_version' => (string) $preset['version'],
            'lut_path' => $preset['file'] !== null ? ($customRoot . '/presets/' . $preset['file']) : null,
            'template_path' => $container->config->getTemplatePath('vertical-three-v1'),
            'output_print_path' => $tmpDir . '/print-' . $lutId . '-' . $i . '.jpg',
            'output_preview_path' => $tmpDir . '/preview-' . $lutId . '-' . $i . '.jpg',
        ];

        $t0 = microtime(true);
        $lastRes = $container->renderer->renderFromManifest($tmpDir, $manifest, 'benchmark-' . $lutId . '-' . $i);
        $wallMs = (int) round((microtime(true) - $t0) * 1000);
        $runs[] = $wallMs;
        $allWallTimes[] = $wallMs;
    }

    sort($runs);
    $results[$lutId] = [
        'lut_id' => $lutId,
        'runs_wall_ms' => $runs,
        'pipeline_duration_ms' => $lastRes['duration_ms'],
        'p50_wall_ms' => $runs[0],
        'max_wall_ms' => $runs[count($runs) - 1],
        'output_dimensions' => $lastRes['width'] . 'x' . $lastRes['height'],
        'output_bytes' => $lastRes['bytes'],
        'sha256' => $lastRes['sha256'],
        'within_2500ms_target' => $runs[count($runs) - 1] <= 2500,
        'within_10s_target' => $runs[count($runs) - 1] <= 10_000,
    ];
}

$container->assetStore->removeDirectoryRecursive($tmpDir);

sort($allWallTimes);
$count = count($allWallTimes);
$p50Index = (int) floor(($count - 1) * 0.50);
$p95Index = (int) floor(($count - 1) * 0.95);
$p50Ms = $allWallTimes[$p50Index];
$p95Ms = $allWallTimes[$p95Index];
$maxMs = $allWallTimes[$count - 1];

$cpuInfo = 'unknown';
if (is_file('/proc/cpuinfo')) {
    foreach (file('/proc/cpuinfo') ?: [] as $line) {
        if (str_starts_with($line, 'model name')) {
            $parts = explode(':', $line, 2);
            $cpuInfo = trim($parts[1] ?? 'unknown');
            break;
        }
    }
}

$memTotalMiB = 0;
if (is_file('/proc/meminfo')) {
    foreach (file('/proc/meminfo') ?: [] as $line) {
        if (str_starts_with($line, 'MemTotal:')) {
            if (preg_match('/(\d+)/', $line, $m) === 1) {
                $memTotalMiB = (int) round(((int) $m[1]) / 1024);
            }
            break;
        }
    }
}

$pyVer = trim((string) shell_exec('python3 -c "import platform, PIL, numpy; print(f\'Python {platform.python_version()}, Pillow {PIL.__version__}, NumPy {numpy.__version__}\')"'));

$report = [
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'environment' => [
        'os' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'),
        'cpu_model' => $cpuInfo,
        'ram_total_mib' => $memTotalMiB,
        'php_version' => PHP_VERSION,
        'python_stack' => $pyVer,
    ],
    'summary' => [
        'total_runs' => $count,
        'p50_ms' => $p50Ms,
        'p95_ms' => $p95Ms,
        'max_ms' => $maxMs,
        'target_p95_ms' => 2500,
        'target_max_ms' => 10_000,
        'p95_within_2500ms_target' => $p95Ms <= 2500,
        'all_within_10s_target' => $maxMs <= 10_000,
    ],
    'benchmarks' => $results,
];

$reportFile = dirname($customRoot) . '/plan/benchmark-report.json';
file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($p95Ms > 2500) {
    exit(1);
}
