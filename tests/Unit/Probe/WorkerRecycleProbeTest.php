<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Nowo\HealthKitBundle\Probe\HealthStatus;
use Nowo\HealthKitBundle\Probe\WorkerRecycleProbe;
use Nowo\HealthKitBundle\Runtime\WorkerRecycleState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(WorkerRecycleProbe::class)]
final class WorkerRecycleProbeTest extends TestCase
{
    #[Test]
    public function degradedWhileTheLastRecycleIsRecent(): void
    {
        $clock = new MockClock('2026-11-15 10:00:00');
        $state = new WorkerRecycleState(new ArrayAdapter(), $clock);
        $probe = new WorkerRecycleProbe($state, $clock, 300);

        $result = $probe->check(1.0);
        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame(['recycles_total' => 0, 'last_recycle_age_seconds' => null, 'last_reason' => null], $result->data);

        $state->record('RedisException');
        $clock->sleep(10);
        $result = $probe->check(1.0);
        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame(['recycles_total' => 1, 'last_recycle_age_seconds' => 10, 'last_reason' => 'RedisException'], $result->data);

        $clock->sleep(290);
        self::assertSame(HealthStatus::Ok, $probe->check(1.0)->status);
        self::assertSame(HealthStatus::Ok, (new WorkerRecycleProbe($state, $clock, 0))->check(1.0)->status);
    }
}
