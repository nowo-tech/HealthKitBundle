<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Metrics;

use Nowo\HealthKitBundle\Health\HealthChecker;
use Nowo\HealthKitBundle\Health\RegisteredProbe;
use Nowo\HealthKitBundle\Metrics\MetricSample;
use Nowo\HealthKitBundle\Metrics\ProbeMetricsProvider;
use Nowo\HealthKitBundle\Probe\ProbeResult;
use Nowo\HealthKitBundle\Tests\Fixtures\StubProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ProbeMetricsProvider::class)]
final class ProbeMetricsProviderTest extends TestCase
{
    #[Test]
    public function exportsStatusDurationAndNumericData(): void
    {
        $checker = new HealthChecker([
            new RegisteredProbe('messenger.async', new StubProbe(ProbeResult::degraded('busy', ['messages' => 12, 'warn_at' => null, 'mode' => 'x', 'worker' => true])), ['ready'], true, 1.0),
            new RegisteredProbe('frankenphp', new StubProbe(ProbeResult::ok()), ['live'], true, 1.0),
        ], new MockClock());

        [$status, $duration, $data] = (new ProbeMetricsProvider($checker))->collect();

        self::assertSame('health_probe_status', $status->name);
        self::assertSame([0.5, 1.0], array_map(static fn (MetricSample $s): float => $s->value, $status->samples));
        self::assertSame('health_probe_duration_seconds', $duration->name);
        self::assertSame(['probe' => 'frankenphp'], $duration->samples[1]->labels);
        self::assertSame('health_probe_data', $data->name);
        self::assertSame([
            [['probe' => 'messenger.async', 'key' => 'messages'], 12.0],
            [['probe' => 'messenger.async', 'key' => 'worker'], 1.0],
        ], array_map(static fn (MetricSample $s): array => [$s->labels, $s->value], $data->samples));
    }
}
