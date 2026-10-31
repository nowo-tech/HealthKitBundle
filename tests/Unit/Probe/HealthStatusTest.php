<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Nowo\HealthKitBundle\Probe\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HealthStatus::class)]
final class HealthStatusTest extends TestCase
{
    #[Test]
    public function worstPicksTheHighestSeverity(): void
    {
        self::assertSame(HealthStatus::Degraded, HealthStatus::Ok->worst(HealthStatus::Degraded));
        self::assertSame(HealthStatus::Error, HealthStatus::Degraded->worst(HealthStatus::Error));
        self::assertSame(HealthStatus::Error, HealthStatus::Error->worst(HealthStatus::Ok));
        self::assertSame(HealthStatus::Ok, HealthStatus::Ok->worst(HealthStatus::Ok));
    }

    #[Test]
    public function gaugeValues(): void
    {
        self::assertSame(1.0, HealthStatus::Ok->gaugeValue());
        self::assertSame(0.5, HealthStatus::Degraded->gaugeValue());
        self::assertSame(0.0, HealthStatus::Error->gaugeValue());
        self::assertSame([0, 1, 2], [HealthStatus::Ok->severity(), HealthStatus::Degraded->severity(), HealthStatus::Error->severity()]);
    }
}
