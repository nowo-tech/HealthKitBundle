<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Runtime;

use Nowo\HealthKitBundle\Runtime\WorkerRecycleState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[CoversClass(WorkerRecycleState::class)]
final class WorkerRecycleStateTest extends TestCase
{
    #[Test]
    public function recordsCountTimeAndReason(): void
    {
        $pool  = new ArrayAdapter();
        $clock = new MockClock('2026-11-15 10:00:00');
        $state = new WorkerRecycleState($pool, $clock);

        self::assertTrue($state->isPersistent());
        self::assertSame(['recycles_total' => 0, 'last_recycle_at' => null, 'last_reason' => null], $state->snapshot());
        self::assertTrue($state->record('RedisException'));
        $clock->sleep(60);
        self::assertTrue($state->record('Predis\\Connection\\ConnectionException'));

        self::assertSame([
            'recycles_total'  => 2,
            'last_recycle_at' => $clock->now()->getTimestamp(),
            'last_reason'     => 'Predis\\Connection\\ConnectionException',
        ], $state->snapshot());
    }

    #[Test]
    public function withoutPoolNothingIsRecorded(): void
    {
        $state = new WorkerRecycleState(null, new MockClock());

        self::assertFalse($state->isPersistent());
        self::assertFalse($state->record('RedisException'));
        self::assertSame(0, $state->snapshot()['recycles_total']);
    }

    #[Test]
    public function malformedRecordsReadAsZero(): void
    {
        $pool = new ArrayAdapter();
        $item = $pool->getItem(WorkerRecycleState::CACHE_KEY);
        $pool->save($item->set('garbage'));
        $state = new WorkerRecycleState($pool, new MockClock());
        self::assertSame(['recycles_total' => 0, 'last_recycle_at' => null, 'last_reason' => null], $state->snapshot());

        $pool->save($item->set(['recycles_total' => '3', 'last_recycle_at' => 1.5, 'last_reason' => 7]));
        $snapshot = (new WorkerRecycleState($pool, new MockClock()))->snapshot();
        self::assertSame(0, $snapshot['recycles_total']);
        self::assertNull($snapshot['last_recycle_at']);
        self::assertNull($snapshot['last_reason']);
    }

    #[Test]
    public function poolFailuresNeverPropagate(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('pool down'));
        $state = new WorkerRecycleState($pool, new MockClock());

        self::assertFalse($state->record('RedisException'));
        self::assertSame(0, $state->snapshot()['recycles_total']);
    }
}
