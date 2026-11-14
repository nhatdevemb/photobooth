<?php

declare(strict_types=1);

namespace Photobooth\Operator\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    private static ?int $mockTimestamp = null;

    public static function setMockTimestamp(?int $timestamp): void
    {
        self::$mockTimestamp = $timestamp;
    }

    public static function nowUnix(): int
    {
        if (self::$mockTimestamp !== null) {
            return self::$mockTimestamp;
        }
        $envMock = getenv('MOCK_CLOCK_TIMESTAMP');
        if ($envMock !== false && $envMock !== '' && is_numeric($envMock)) {
            return (int) $envMock;
        }
        return time();
    }

    public static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', self::nowUnix());
    }

    public static function fromUnixIso(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    public static function toUnix(string $isoUtc): int
    {
        $ts = strtotime($isoUtc);
        return $ts === false ? 0 : $ts;
    }

    public static function formatLocal(string $isoUtc, string $timezone = 'Asia/Ho_Chi_Minh'): string
    {
        $dt = new DateTimeImmutable($isoUtc, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s P');
    }
}

