<?php

declare(strict_types=1);

namespace Photobooth\Operator;

use Photobooth\Operator\Auth\AuthService;
use Photobooth\Operator\Catalog\TemplateCatalog;
use Photobooth\Operator\Catalog\LutCatalog;
use Photobooth\Operator\Camera\CameraAdapterInterface;
use Photobooth\Operator\Camera\BrowserCameraAdapter;
use Photobooth\Operator\Camera\Gphoto2CameraAdapter;
use Photobooth\Operator\Camera\MockCameraAdapter;
use Photobooth\Operator\Camera\DigiCamControlCameraAdapter;
use Photobooth\Operator\Config\AppConfig;
use Photobooth\Operator\Event\EventSettings;
use Photobooth\Operator\Image\BeautyService;
use Photobooth\Operator\Image\Renderer;
use Photobooth\Operator\Jobs\WorkerRunner;
use Photobooth\Operator\Maintenance\HealthService;
use Photobooth\Operator\Maintenance\MaintenanceService;
use Photobooth\Operator\Maintenance\StructuredLogger;
use Photobooth\Operator\Printer\CupsPrintAdapter;
use Photobooth\Operator\Printer\MockPrintAdapter;
use Photobooth\Operator\Printer\PrintAdapterInterface;
use Photobooth\Operator\Printer\PrintService;
use Photobooth\Operator\Session\SessionService;
use Photobooth\Operator\Share\GuestRateLimiter;
use Photobooth\Operator\Share\ShareService;
use Photobooth\Operator\Storage\AssetStore;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Storage\MigrationRunner;

final class Container
{
    public readonly TemplateCatalog $catalog;
    public readonly EventSettings $events;
    public readonly LutCatalog $luts;
    public readonly AppConfig $config;
    public readonly StructuredLogger $logger;
    public readonly Database $db;
    public readonly MigrationRunner $migrations;
    public readonly AssetStore $assetStore;
    public readonly AuthService $authService;
    public readonly Renderer $renderer;
    public readonly BeautyService $beautyService;
    public readonly CameraAdapterInterface $cameraAdapter;
    public readonly PrintAdapterInterface $printAdapter;
    public readonly ShareService $shareService;
    public readonly GuestRateLimiter $guestRateLimiter;
    public readonly SessionService $sessionService;
    public readonly PrintService $printService;
    public readonly MaintenanceService $maintenanceService;
    public readonly HealthService $healthService;
    public readonly WorkerRunner $workerRunner;

    /**
     * @param array<string, string> $envOverrides
     */
    public static function boot(array $envOverrides = [], bool $autoMigrate = true): self
    {
        return new self($envOverrides, $autoMigrate);
    }

    /**
     * @param array<string, string> $envOverrides
     */
    public function __construct(array $envOverrides = [], bool $autoMigrate = true)
    {
        $this->config = AppConfig::fromEnvironment($envOverrides);
        $this->logger = new StructuredLogger($this->config->dataDir);
        $this->db = new Database($this->config->databasePath, $this->config->sqliteBusyTimeoutMs);
        $this->migrations = new MigrationRunner($this->db, $this->config->customRoot . '/migrations');

        if ($autoMigrate) {
            $this->migrations->migrate();
        }

        $this->catalog = new TemplateCatalog($this->config, $this->db);
        $this->assetStore = new AssetStore($this->config, $this->logger);
        $this->authService = new AuthService($this->db, $this->config);
        $this->renderer = new Renderer($this->config, $this->logger);
        $this->beautyService = new BeautyService($this->db, $this->config, $this->assetStore, $this->renderer);
        $this->luts = new LutCatalog($this->config, $this->db, $this->renderer);

        $this->cameraAdapter = match ($this->config->cameraAdapter) {
            'digicamcontrol' => new DigiCamControlCameraAdapter($this->config, $this->renderer),
            'hardware' => new Gphoto2CameraAdapter($this->config, $this->renderer),
            'browser' => new BrowserCameraAdapter($this->renderer),
            default => new MockCameraAdapter($this->config, $this->renderer),
        };

        $this->printAdapter = $this->config->printerAdapter === 'hardware'
            ? new CupsPrintAdapter($this->config)
            : new MockPrintAdapter($this->config);

        $this->events = new EventSettings($this->db, $this->catalog, $this->renderer, $this->printAdapter->getMode(), $this->config->shareTtlHours);
        $this->shareService = new ShareService($this->db, $this->config, $this->assetStore);
        $this->guestRateLimiter = new GuestRateLimiter($this->config);
        $this->sessionService = new SessionService(
            $this->db,
            $this->config,
            $this->assetStore,
            $this->shareService,
            $this->logger,
            $this->catalog,
            $this->luts
        );
        $this->printService = new PrintService(
            $this->db,
            $this->config,
            $this->assetStore,
            $this->printAdapter,
            $this->logger
        );
        $this->maintenanceService = new MaintenanceService(
            $this->db,
            $this->config,
            $this->assetStore,
            $this->logger,
            $this->guestRateLimiter
        );
        $this->healthService = new HealthService(
            $this->db,
            $this->config,
            $this->migrations,
            $this->assetStore,
            $this->cameraAdapter,
            $this->printAdapter,
            $this->maintenanceService,
            $this->beautyService
        );
        $this->workerRunner = new WorkerRunner(
            $this->db,
            $this->config,
            $this->assetStore,
            $this->cameraAdapter,
            $this->renderer,
            $this->printService,
            $this->logger,
            $this->catalog,
            $this->luts,
            beauty: $this->beautyService
        );
    }
}

