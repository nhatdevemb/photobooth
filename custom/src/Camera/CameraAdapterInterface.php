<?php

declare(strict_types=1);

namespace Photobooth\Operator\Camera;

interface CameraAdapterInterface
{
    public function getMode(): string;

    /**
     * @return array{status: string, frame_urls: list<string>}
     */
    public function startPreview(): array;

    public function stopPreview(): void;

    /**
     * Capture a photo into the given destination directory.
     *
     * @return array{
     *   temp_path: string,
     *   sha256: string,
     *   width: int,
     *   height: int,
     *   bytes: int,
     *   exif_orientation: int,
     *   icc_status: string
     * }
     */
    public function capture(
        string $destinationDir,
        int $slot,
        int $version,
        int $deadlineUnix,
        ?string $faultScenario = null
    ): array;

    public function disconnect(): void;

    /**
     * @return array{
     *   adapter: string,
     *   mode: string,
     *   camera_state: string,
     *   preview_state: string,
     *   model: string,
     *   details: string
     * }
     */
    public function health(): array;
}

