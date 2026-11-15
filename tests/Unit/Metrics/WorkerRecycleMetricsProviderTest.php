<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Metrics;

use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\WorkerRecycleMetricsProvider;
use Nowo\HealthKitBundle\Runtime\WorkerRecycleState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(WorkerRecycleMetricsProvider::class)]
final class WorkerRecycleMetricsProviderTest extends TestCase
{
    /**
     * @param list<MetricFamily> $families
     *
     * @return array<string, float>
     */
    private function values(array $families): array
    {
        $out = [];
        foreach ($families as $family) {
            $out[$family->name] = $family->samples[0]->value;
        }

        return $out;
    }

    #[Test]
    public function exportsTheRecord(): void
    {
        $clock    = new MockClock('2026-11-15 10:00:00');
        $state    = new WorkerRecycleState(new ArrayAdapter(), $clock);
        $provider = new WorkerRecycleMetricsProvider($state);

        self::assertSame([
            'health_kit_worker_recycle_enabled'    => 1.0,
            'health_kit_worker_recycle_persistent' => 1.0,
            'health_kit_worker_recycles_total'     => 0.0,
        ], $this->values($provider->collect()));

        $state->record('RedisException');
        self::assertSame([
            'health_kit_worker_recycle_enabled'                => 1.0,
            'health_kit_worker_recycle_persistent'             => 1.0,
            'health_kit_worker_recycles_total'                 => 1.0,
            'health_kit_worker_recycle_last_timestamp_seconds' => (float) $clock->now()->getTimestamp(),
        ], $this->values($provider->collect()));

        self::assertSame(0.0, $this->values((new WorkerRecycleMetricsProvider(new WorkerRecycleState(null, $clock)))->collect())['health_kit_worker_recycle_persistent']);
    }
}
