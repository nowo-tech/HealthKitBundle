<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Nowo\HealthKitBundle\Probe\HealthStatus;
use Nowo\HealthKitBundle\Probe\MessengerTransportProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

#[CoversClass(MessengerTransportProbe::class)]
final class MessengerTransportProbeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int|null, int|null, HealthStatus}>
     */
    public static function counts(): iterable
    {
        yield 'no thresholds' => [5000, null, null, HealthStatus::Ok];
        yield 'below warn' => [9, 10, 100, HealthStatus::Ok];
        yield 'at warn' => [10, 10, 100, HealthStatus::Degraded];
        yield 'at fail' => [100, 10, 100, HealthStatus::Error];
        yield 'fail only' => [3, null, 3, HealthStatus::Error];
        yield 'negative count' => [-4, 1, null, HealthStatus::Ok];
    }

    #[Test]
    #[DataProvider('counts')]
    public function comparesTheDepthToThresholds(int $count, ?int $warn, ?int $fail, HealthStatus $expected): void
    {
        $transport = $this->createStub(MessageCountAwareInterface::class);
        $transport->method('getMessageCount')->willReturn($count);

        $result = (new MessengerTransportProbe($transport, $warn, $fail))->check(1.0);

        self::assertSame($expected, $result->status);
        self::assertSame(['messages' => max(0, $count), 'warn_at' => $warn, 'fail_at' => $fail], $result->data);
    }

    #[Test]
    public function nonCountableTransportIsDegraded(): void
    {
        $result = (new MessengerTransportProbe(new stdClass(), 1, 2))->check(1.0);

        self::assertSame(HealthStatus::Degraded, $result->status);
        self::assertSame('The transport cannot report its message count.', $result->message);
    }
}
