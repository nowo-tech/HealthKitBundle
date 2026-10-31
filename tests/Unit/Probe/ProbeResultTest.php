<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Nowo\HealthKitBundle\Probe\HealthStatus;
use Nowo\HealthKitBundle\Probe\ProbeResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProbeResult::class)]
final class ProbeResultTest extends TestCase
{
    #[Test]
    public function namedConstructors(): void
    {
        $ok = ProbeResult::ok(['a' => 1], 'fine');
        self::assertSame(HealthStatus::Ok, $ok->status);
        self::assertSame('fine', $ok->message);
        self::assertSame(['a' => 1], $ok->data);

        $degraded = ProbeResult::degraded('slow', ['b' => true]);
        self::assertSame(HealthStatus::Degraded, $degraded->status);
        self::assertSame(['b' => true], $degraded->data);

        $error = ProbeResult::error('down');
        self::assertSame(HealthStatus::Error, $error->status);
        self::assertSame('down', $error->message);
        self::assertSame([], $error->data);
    }
}
