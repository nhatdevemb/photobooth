<?php

declare(strict_types=1);

namespace Photobooth\Operator\Maintenance;

use Photobooth\Operator\Camera\CameraAdapterInterface;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Printer\PrintAdapterInterface;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Storage\MigrationRunner;
use Photobooth\Operator\Support\Clock;

final class HealthService
{
    private Database $db;
    private AppConfig $config;
    private MigrationRunner $migrations;
    private AssetStore $assetStore;
    private CameraAdapterInterface $camera;
    private PrintAdapterInterface $printer;
    private MaintenanceService $maintenance;

    public function __construct(
        Database $db,
        AppConfig $config,
        MigrationRunner $migrations,
        AssetStore $assetStore,
        CameraAdapterInterface $camera,
        PrintAdapterInterface $printer,
        MaintenanceService $maintenance
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->migrations = $migrations;
        $this->assetStore = $assetStore;
        $this->camera = $camera;
        $this->printer = $printer;
        $this->maintenance = $maintenance;
    }

    /**
     * GET /custom/api/health
     *
     * @return array<string, mixed>
     */
    public function getHealthReport(): array
    {
        $pdo = $this->db->pdo();
        $migrationStatus = $this->migrations->status();

        // Worker status
        $wStmt = $pdo->query('SELECT * FROM worker_state ORDER BY heartbeat_at DESC LIMIT 1');
        $wRow = $wStmt !== false ? $wStmt->fetch() : false;

        $queuedJobs = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'QUEUED'")->fetchColumn();
        $runningJobs = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'RUNNING'")->fetchColumn();

        $nowUnix = Clock::nowUnix();
        $workerState = 'stopped';
        $lastHeartbeat = null;
        $workerId = null;

        if (is_array($wRow)) {
            $workerId = (string) $wRow['worker_id'];
            $lastHeartbeat = (string) $wRow['heartbeat_at'];
            $hbUnix = Clock::toUnix($lastHeartbeat);
            $ageSeconds = max(0, $nowUnix - $hbUnix);
            $workerState = ($ageSeconds <= $this->config->workerStaleSeconds) ? 'healthy' : 'stale';
        }

        // Camera & Printer health
        $cameraHealth = $this->camera->health();
        $printerHealth = $this->printer->health();

        $trackedPrints = (int) $pdo->query(
            "SELECT COUNT(*) FROM print_requests WHERE status IN ('PENDING', 'SUBMITTING', 'QUEUED', 'PRINTING')"
        )->fetchColumn();
        $unknownPrints = (int) $pdo->query(
            "SELECT COUNT(*) FROM print_requests WHERE status = 'UNKNOWN'"
        )->fetchColumn();

        // Disk & Storage
        $freeBytes = $this->assetStore->getFreeDiskBytes();
        $isDiskLow = $this->assetStore->isDiskLow();
        $isWritable = is_writable($this->config->dataDir);

        // Held retention cleanup
        $heldCleanupCount = $this->maintenance->countExpiredSessionsHeldByPrints();

        $warnings = [];
        if ($this->config->isMockMode()) {
            $warnings[] = 'Hệ thống đang chạy ở CHẾ ĐỘ MÔ PHỎNG (MOCK). Không sử dụng kết quả này làm nghiệm thu phần cứng.';
        }
        if ($workerState === 'stale') {
            $warnings[] = 'Worker xử lý nền bị trễ nhịp tim (stale > 10s). Vui lòng kiểm tra dịch vụ worker.';
        } elseif ($workerState === 'stopped') {
            $warnings[] = 'Chưa ghi nhận nhịp tim từ Worker xử lý nền.';
        }
        if (!in_array($cameraHealth['camera_state'], ['connected', 'client_managed'], true)) {
            $warnings[] = 'Máy ảnh không sẵn sàng: ' . $cameraHealth['details'];
        }
        if ($printerHealth['queue_state'] !== 'ready') {
            $warnings[] = 'Hàng đợi máy in gặp lỗi: ' . $printerHealth['details'];
        }
        if ($unknownPrints > 0) {
            $warnings[] = "Có {$unknownPrints} yêu cầu in ở trạng thái KHÔNG XÁC ĐỊNH (UNKNOWN). Vui lòng kiểm tra bản giấy thực tế trước khi in lại.";
        }
        if ($isDiskLow) {
            $warnings[] = 'Dung lượng ổ đĩa trống dưới 2 GiB. Đã chặn chụp, xử lý và in mới.';
        }
        if ($heldCleanupCount > 0) {
            $warnings[] = "Có {$heldCleanupCount} phiên quá hạn lưu trữ đang được giữ lại do còn yêu cầu in chưa giải quyết.";
        }

        $overallStatus = 'ok';
        if (
            !$migrationStatus['ok']
            || !$isWritable
            || $isDiskLow
            || $cameraHealth['camera_state'] === 'error'
            || $printerHealth['queue_state'] === 'error'
        ) {
            $overallStatus = 'error';
        } elseif ($workerState !== 'healthy' || $unknownPrints > 0 || $heldCleanupCount > 0) {
            $overallStatus = 'degraded';
        }

        return [
            'status' => $overallStatus,
            'mode' => $this->config->isMockMode() ? 'mock' : 'hardware',
            'simulation_label' => $this->config->cameraAdapter === 'digicamcontrol'
                ? 'CANON QUA DIGICAMCONTROL · MÁY IN ' . ($this->config->printerAdapter === 'mock' ? 'MÔ PHỎNG (MOCK)' : 'PHẦN CỨNG')
                : ($this->config->cameraAdapter === 'browser'
                ? 'CAMERA TRÌNH DUYỆT · MÁY IN ' . ($this->config->printerAdapter === 'mock' ? 'MÔ PHỎNG (MOCK)' : 'PHẦN CỨNG')
                : ($this->config->isMockMode() ? 'MÔ PHỎNG (MOCK)' : 'PHẦN CỨNG THẬT')),
            'app_version' => $this->config->appVersion,
            'upstream_sha' => $this->config->upstreamSha,
            'timestamp' => Clock::nowIso(),
            'database' => [
                'connected' => true,
                'migrations_ok' => $migrationStatus['ok'],
                'applied_migrations' => $migrationStatus['applied_count'],
                'pending_migrations' => $migrationStatus['pending_count'],
            ],
            'worker' => [
                'status' => $workerState,
                'worker_id' => $workerId,
                'last_heartbeat_at' => $lastHeartbeat,
                'queued_jobs' => $queuedJobs,
                'running_jobs' => $runningJobs,
            ],
            'camera' => $cameraHealth,
            'preview' => [
                'state' => $cameraHealth['preview_state'],
                'mode' => $cameraHealth['mode'],
            ],
            'printer' => [
                'adapter' => $printerHealth['adapter'],
                'mode' => $printerHealth['mode'],
                'queue' => $printerHealth['queue'],
                'queue_state' => $printerHealth['queue_state'],
                'tracked_requests' => $trackedPrints,
                'unknown_requests' => $unknownPrints,
                'details' => $printerHealth['details'],
            ],
            'disk' => [
                'free_bytes' => $freeBytes,
                'free_gib' => round($freeBytes / (1024 * 1024 * 1024), 2),
                'threshold_bytes' => $this->config->diskLowBytes,
                'is_low' => $isDiskLow,
                'writable' => $isWritable,
            ],
            'retention' => [
                'retention_days' => $this->config->sessionRetentionDays,
                'share_ttl_hours' => $this->config->shareTtlHours,
                'held_sessions_count' => $heldCleanupCount,
            ],
            'warnings' => $warnings,
        ];
    }
}

