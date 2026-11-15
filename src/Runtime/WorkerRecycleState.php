<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Runtime;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Throwable;

use function is_array;
use function is_int;
use function is_string;

/**
 * Records worker recycles in a PSR-6 pool shared by the workers of one host, for metrics and the health probe.
 *
 * The recycling worker dies right after recording, so the pool is the only place where the count survives: point
 * `runtime.worker_recycle.state_pool` to a pool that does not depend on the connection being recycled (a filesystem
 * pool such as `cache.system`, never the Redis pool). Without a pool nothing is recorded. The read-increment-write is
 * not atomic: two workers recycling at the same instant may count once. Pool failures are swallowed so they never stop
 * a recycle. Nothing is kept in the object between calls.
 */
final readonly class WorkerRecycleState
{
    /** @var string cache key of the record */
    public const CACHE_KEY = 'nowo_health_kit.worker_recycle';

    /** @var int seconds the record lives in the pool, renewed by every recycle */
    public const TTL = 2592000;

    /**
     * @param CacheItemPoolInterface|null $pool shared pool (null: nothing recorded)
     * @param ClockInterface $clock clock for the recycle timestamp
     */
    public function __construct(
        private ?CacheItemPoolInterface $pool,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether recycles are recorded (a pool is configured).
     *
     * @return bool true with a pool
     */
    public function isPersistent(): bool
    {
        return $this->pool instanceof CacheItemPoolInterface;
    }

    /**
     * Counts one recycle with its time and reason.
     *
     * @param string $reason non-secret reason
     *
     * @return bool true when the record was saved
     */
    public function record(string $reason): bool
    {
        if (!$this->pool instanceof CacheItemPoolInterface) {
            return false;
        }

        try {
            $current = $this->snapshot();
            $item    = $this->pool->getItem(self::CACHE_KEY);
            $item->expiresAfter(self::TTL);
            $item->set([
                'recycles_total'  => $current['recycles_total'] + 1,
                'last_recycle_at' => $this->clock->now()->getTimestamp(),
                'last_reason'     => $reason,
            ]);

            return $this->pool->save($item);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Reads the record.
     *
     * @return array{recycles_total: int, last_recycle_at: int|null, last_reason: string|null} zeros when nothing was recorded or the pool fails
     */
    public function snapshot(): array
    {
        $empty = ['recycles_total' => 0, 'last_recycle_at' => null, 'last_reason' => null];
        if (!$this->pool instanceof CacheItemPoolInterface) {
            return $empty;
        }

        try {
            $item = $this->pool->getItem(self::CACHE_KEY);
            $data = $item->isHit() ? $item->get() : null;
        } catch (Throwable) {
            return $empty;
        }
        if (!is_array($data)) {
            return $empty;
        }

        return [
            'recycles_total'  => is_int($data['recycles_total'] ?? null) ? $data['recycles_total'] : 0,
            'last_recycle_at' => is_int($data['last_recycle_at'] ?? null) ? $data['last_recycle_at'] : null,
            'last_reason'     => is_string($data['last_reason'] ?? null) ? $data['last_reason'] : null,
        ];
    }
}
