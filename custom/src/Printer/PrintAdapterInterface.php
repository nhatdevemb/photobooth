<?php

declare(strict_types=1);

namespace Photobooth\Operator\Printer;

interface PrintAdapterInterface
{
    public function getMode(): string;

    /**
     * Submit an immutable finalized JPEG to the printer queue.
     *
     * @return array{
     *   adapter_job_id: string,
     *   status: string,
     *   submitted_at: string
     * }
     */
    public function submit(
        string $printRequestId,
        string $sessionId,
        string $finalAssetId,
        string $finalFilePath,
        string $sha256,
        int $copies = 1,
        ?string $scenarioOverride = null
    ): array;

    /**
     * Query the status of a submitted adapter job.
     * Never infers COMPLETED if the job record is missing.
     *
     * @return array{
     *   status: string,
     *   reason: ?string
     * }
     */
    public function query(
        string $adapterJobId,
        ?string $printRequestId = null,
        ?string $scenarioOverride = null
    ): array;

    /**
     * Reconcile a request that crashed in SUBMITTING state by checking if a receipt/spool job
     * with this printRequestId exists.
     *
     * @return ?array{adapter_job_id: string, status: string}
     */
    public function reconcileSubmittingRequest(string $printRequestId): ?array;

    /**
     * @return array{
     *   adapter: string,
     *   mode: string,
     *   queue: string,
     *   queue_state: string,
     *   details: string
     * }
     */
    public function health(): array;
}

