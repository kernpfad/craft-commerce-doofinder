<?php

declare(strict_types=1);

namespace kernpfad\commercedoofinder\tests\unit;

use kernpfad\commercedoofinder\services\SyncStatusService;
use PHPUnit\Framework\TestCase;

class SyncStatusServiceTest extends TestCase
{
    public function testTimestampIsStaleWhenNeverReindexed(): void
    {
        self::assertTrue(SyncStatusService::isTimestampStale(null, 24, 1_700_000_000));
    }

    public function testTimestampIsStaleWhenOlderThanThreshold(): void
    {
        $now = 1_700_000_000;
        $twentyFiveHoursAgo = $now - (25 * 3600);

        self::assertTrue(SyncStatusService::isTimestampStale($twentyFiveHoursAgo, 24, $now));
    }

    public function testTimestampIsFreshWhenWithinThreshold(): void
    {
        $now = 1_700_000_000;
        $oneHourAgo = $now - 3600;

        self::assertFalse(SyncStatusService::isTimestampStale($oneHourAgo, 24, $now));
    }

    public function testZeroStaleHoursAlwaysMeansStale(): void
    {
        $now = 1_700_000_000;

        self::assertTrue(SyncStatusService::isTimestampStale($now, 0, $now));
    }
}
