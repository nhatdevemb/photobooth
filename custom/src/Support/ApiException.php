<?php

declare(strict_types=1);

namespace Photobooth\Operator\Support;

use RuntimeException;
use Throwable;

final class ApiException extends RuntimeException
{
    private string $errorCode;
    private int $httpStatus;
    /** @var array<string, mixed> */
    private array $details;
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        string $errorCode,
        string $vietnameseMessage,
        int $httpStatus = 400,
        array $details = [],
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($vietnameseMessage, $httpStatus, $previous);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
        $this->headers = $headers;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}

