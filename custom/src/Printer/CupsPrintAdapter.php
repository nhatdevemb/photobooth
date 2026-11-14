<?php

declare(strict_types=1);

namespace Photobooth\Operator\Printer;

use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Support\ApiException;
use Photobooth\Operator\Support\Clock;

/**
 * Hardware CUPS printer adapter for Canon SELPHY CP1500 (Milestone H2 target).
 * Never silently falls back to mock when CUPS or the configured queue is unavailable.
 */
final class CupsPrintAdapter implements PrintAdapterInterface
{
    private AppConfig $config;

    public function __construct(AppConfig $config)
    {
        $this->config = $config;
    }

    public function getMode(): string
    {
        return 'hardware';
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
        $health = $this->health();
        if ($health['queue_state'] !== 'ready') {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Hàng đợi máy in CUPS chưa sẵn sàng. Không tự động chuyển về chế độ mô phỏng.',
                503,
                ['submitted_to_spool' => false]
            );
        }

        $jobTitle = 'pb-req-' . $printRequestId;
        $cmd = [
            'lp',
            '-d',
            $this->config->printerQueue,
            '-n',
            (string) $copies,
            '-t',
            $jobTitle,
            $finalFilePath,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new ApiException(
                'DEVICE_UNAVAILABLE',
                'Không thể khởi chạy lệnh lp của CUPS.',
                503,
                ['submitted_to_spool' => false]
            );
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            throw new ApiException(
                'PRINT_SUBMIT_FAILED',
                'Lệnh lp gửi tới CUPS thất bại: ' . trim($stderr),
                503,
                ['submitted_to_spool' => false]
            );
        }

        // Extract request id is <queue>-<job>
        if (preg_match('/request id is ([^\s]+)/i', $stdout, $m) === 1) {
            return [
                'adapter_job_id' => $m[1],
                'status' => 'QUEUED',
                'submitted_at' => Clock::nowIso(),
            ];
        }

        throw new ApiException(
            'PRINT_OUTCOME_UNKNOWN',
            'Lệnh lp đã chạy nhưng không trả về mã job CUPS rõ ràng.',
            504,
            ['submitted_to_spool' => true]
        );
    }

    public function query(
        string $adapterJobId,
        ?string $printRequestId = null,
        ?string $scenarioOverride = null
    ): array {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(['lpstat', '-W', 'all', '-o', $this->config->printerQueue], $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new ApiException('DEVICE_UNAVAILABLE', 'Không thể truy vấn trạng thái CUPS (lpstat).', 503);
        }
        fclose($pipes[0]);
        $allJobs = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            throw new ApiException('DEVICE_UNAVAILABLE', 'Truy vấn hàng đợi CUPS thất bại.', 503);
        }

        if (!str_contains($allJobs, $adapterJobId)) {
            // Do not infer COMPLETED from not-found
            return [
                'status' => 'UNKNOWN',
                'reason' => 'Không tìm thấy job trong lịch sử CUPS; cần đối chiếu bản in thực tế.',
            ];
        }

        $procCompleted = @proc_open(['lpstat', '-W', 'completed', '-o', $this->config->printerQueue], $descriptors, $pipes2);
        if (is_resource($procCompleted)) {
            fclose($pipes2[0]);
            $completedJobs = (string) stream_get_contents($pipes2[1]);
            fclose($pipes2[1]);
            fclose($pipes2[2]);
            proc_close($procCompleted);
            if (str_contains($completedJobs, $adapterJobId)) {
                return ['status' => 'COMPLETED', 'reason' => null];
            }
        }

        return ['status' => 'QUEUED', 'reason' => null];
    }

    public function reconcileSubmittingRequest(string $printRequestId): ?array
    {
        return null;
    }

    public function health(): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(['lpstat', '-p', $this->config->printerQueue], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [
                'adapter' => 'CupsPrintAdapter',
                'mode' => 'hardware',
                'queue' => $this->config->printerQueue,
                'queue_state' => 'error',
                'details' => 'Chưa cài đặt cups-client (lpstat).',
            ];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode === 0 && str_contains(strtolower($stdout), 'enabled')) {
            return [
                'adapter' => 'CupsPrintAdapter',
                'mode' => 'hardware',
                'queue' => $this->config->printerQueue,
                'queue_state' => 'ready',
                'details' => trim($stdout),
            ];
        }

        return [
            'adapter' => 'CupsPrintAdapter',
            'mode' => 'hardware',
            'queue' => $this->config->printerQueue,
            'queue_state' => 'error',
            'details' => 'Không tìm thấy hàng đợi CUPS "' . $this->config->printerQueue . '".',
        ];
    }
}

