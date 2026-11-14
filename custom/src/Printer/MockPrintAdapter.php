<?php

declare(strict_types=1);

namespace Photobooth\Operator\Printer;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;

final class MockPrintAdapter implements PrintAdapterInterface
{
    private AppConfig $config;
    private string $receiptsDir;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
        $this->receiptsDir = rtrim($config->dataDir, '/') . '/prints/receipts';
        if (!is_dir($this->receiptsDir)) {
            @mkdir($this->receiptsDir, 0750, true);
        }
    }

    public function getMode(): string
    {
        return 'mock';
    }

    public function receiptPath(string $printRequestId): string
    {
        return $this->receiptsDir . '/' . $printRequestId . '.json';
    }

    /**
     * Count all receipts written to disk (used by soak & idempotency tests).
     */
    public function countReceipts(): int
    {
        $files = glob($this->receiptsDir . '/*.json') ?: [];
        return count($files);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listReceipts(): array
    {
        $files = glob($this->receiptsDir . '/*.json') ?: [];
        sort($files, SORT_STRING);
        $list = [];
        foreach ($files as $file) {
            $decoded = json_decode((string) @file_get_contents($file), true);
            if (is_array($decoded)) {
                $list[] = $decoded;
            }
        }
        return $list;
    }

    public function submit(
        string $printRequestId,
        string $sessionId,
        string $finalAssetId,
        string $finalFilePath,
        string $sha256,
        int $copies = 1,
        ?string $scenarioOverride = null
    ): array {
        $scenario = $scenarioOverride ?? $this->config->mockPrinterScenario;

        if ($scenario === 'disconnect') {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Máy in đang mất kết nối hoặc queue không sẵn sàng.',
                503,
                ['submitted_to_spool' => false]
            );
        }

        if ($scenario === 'pre_submit_fail') {
            throw new ApiException(
                'PRINT_SUBMIT_FAILED',
                'Lỗi từ chối lệnh in trước khi gửi tới hàng đợi (pre-submit error).',
                503,
                ['submitted_to_spool' => false]
            );
        }

        if ($scenario === 'post_submit_timeout') {
            // Simulates timeout after starting submission: outcome is ambiguous -> UNKNOWN
            throw new ApiException(
                'PRINT_OUTCOME_UNKNOWN',
                'Hết thời gian chờ phản hồi sau khi gửi lệnh in. Trạng thái không xác định (UNKNOWN), vui lòng kiểm tra máy in trước khi in lại.',
                504,
                ['submitted_to_spool' => true]
            );
        }

        if (!is_file($finalFilePath)) {
            throw new ApiException(
                'INTEGRITY_ERROR',
                'Không tìm thấy tệp ảnh cuối để gửi in.',
                409,
                ['submitted_to_spool' => false]
            );
        }

        $actualHash = hash_file('sha256', $finalFilePath) ?: '';
        if (!hash_equals($sha256, $actualHash)) {
            throw new ApiException(
                'INTEGRITY_ERROR',
                'Mã băm SHA-256 của ảnh cuối không khớp khi gửi in.',
                409,
                ['submitted_to_spool' => false]
            );
        }

        $receiptFile = $this->receiptPath($printRequestId);

        // Exclusive creation so the same printRequestId can never produce two receipts
        $fp = @fopen($receiptFile, 'x');
        if ($fp === false) {
            if (is_file($receiptFile)) {
                $existing = json_decode((string) file_get_contents($receiptFile), true);
                if (is_array($existing) && isset($existing['adapter_job_id'])) {
                    return [
                        'adapter_job_id' => (string) $existing['adapter_job_id'],
                        'status' => (string) ($existing['status'] ?? 'QUEUED'),
                        'submitted_at' => (string) ($existing['submitted_at'] ?? Clock::nowIso()),
                    ];
                }
            }
            throw new ApiException(
                'DUPLICATE_PRINT_RECEIPT',
                'Biên nhận lệnh in đã tồn tại cho yêu cầu này.',
                409,
                ['submitted_to_spool' => true]
            );
        }

        $nowIso = Clock::nowIso();
        $adapterJobId = 'mock-cups-' . substr($printRequestId, 0, 8) . '-' . Clock::nowUnix();

        $receiptData = [
            'mode' => 'mock',
            'print_request_id' => $printRequestId,
            'adapter_job_id' => $adapterJobId,
            'session_id' => $sessionId,
            'final_asset_id' => $finalAssetId,
            'sha256' => $sha256,
            'copies' => $copies,
            'queue' => $this->config->printerQueue,
            'status' => 'QUEUED',
            'scenario' => $scenario,
            'submitted_at' => $nowIso,
            'updated_at' => $nowIso,
        ];

        try {
            $json = json_encode($receiptData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            fwrite($fp, $json);
            fflush($fp);
            if (function_exists('fsync')) {
                @fsync($fp);
            }
        } finally {
            fclose($fp);
        }

        return [
            'adapter_job_id' => $adapterJobId,
            'status' => 'QUEUED',
            'submitted_at' => $nowIso,
        ];
    }

    public function query(
        string $adapterJobId,
        ?string $printRequestId = null,
        ?string $scenarioOverride = null
    ): array {
        $scenario = $scenarioOverride ?? $this->config->mockPrinterScenario;

        if ($scenario === 'disconnect') {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Không thể truy vấn hàng đợi máy in do mất kết nối.',
                503
            );
        }

        $receiptFile = null;
        if ($printRequestId !== null && is_file($this->receiptPath($printRequestId))) {
            $receiptFile = $this->receiptPath($printRequestId);
        } else {
            foreach (glob($this->receiptsDir . '/*.json') ?: [] as $f) {
                $d = json_decode((string) @file_get_contents($f), true);
                if (is_array($d) && ($d['adapter_job_id'] ?? '') === $adapterJobId) {
                    $receiptFile = $f;
                    break;
                }
            }
        }

        // IT-10: Never infer COMPLETED from a missing job history record
        if ($receiptFile === null || !is_file($receiptFile)) {
            return [
                'status' => 'UNKNOWN',
                'reason' => 'Không tìm thấy lịch sử job trong hàng đợi in; không thể tự kết luận đã hoàn thành.',
            ];
        }

        $receipt = json_decode((string) file_get_contents($receiptFile), true);
        if (!is_array($receipt)) {
            return [
                'status' => 'UNKNOWN',
                'reason' => 'Dữ liệu biên nhận hàng đợi in không đọc được.',
            ];
        }

        $recScenario = $scenarioOverride ?? (string) ($receipt['scenario'] ?? $scenario);

        if ($recScenario === 'lost_queue_state') {
            return [
                'status' => 'UNKNOWN',
                'reason' => 'Mất trạng thái job trong hàng đợi CUPS/mock; yêu cầu kiểm tra bản giấy thực tế.',
            ];
        }

        if ($recScenario === 'stay_queued') {
            return [
                'status' => 'QUEUED',
                'reason' => null,
            ];
        }

        if ($recScenario === 'printing_only') {
            return [
                'status' => 'PRINTING',
                'reason' => null,
            ];
        }

        // Default normal mock lifecycle: transition QUEUED -> COMPLETED and persist to receipt
        if (($receipt['status'] ?? '') !== 'COMPLETED') {
            $receipt['status'] = 'COMPLETED';
            $receipt['completed_at'] = Clock::nowIso();
            $receipt['updated_at'] = Clock::nowIso();
            $tmp = $receiptFile . '.tmp.' . getmypid();
            file_put_contents($tmp, json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            @rename($tmp, $receiptFile);
        }

        return [
            'status' => 'COMPLETED',
            'reason' => null,
        ];
    }

    public function reconcileSubmittingRequest(string $printRequestId): ?array
    {
        $receiptFile = $this->receiptPath($printRequestId);
        if (!is_file($receiptFile)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($receiptFile), true);
        if (!is_array($decoded) || !isset($decoded['adapter_job_id'])) {
            return null;
        }
        return [
            'adapter_job_id' => (string) $decoded['adapter_job_id'],
            'status' => (string) ($decoded['status'] ?? 'QUEUED'),
        ];
    }

    public function health(): array
    {
        if ($this->config->mockPrinterScenario === 'disconnect') {
            return [
                'adapter' => 'MockPrintAdapter',
                'mode' => 'mock',
                'queue' => $this->config->printerQueue,
                'queue_state' => 'error',
                'details' => 'Máy in mô phỏng đang ở kịch bản mất kết nối.',
            ];
        }

        return [
            'adapter' => 'MockPrintAdapter',
            'mode' => 'mock',
            'queue' => $this->config->printerQueue . ' (MOCK)',
            'queue_state' => 'ready',
            'details' => 'Bộ mô phỏng máy in Canon SELPHY CP1500 sẵn sàng.',
        ];
    }
}

