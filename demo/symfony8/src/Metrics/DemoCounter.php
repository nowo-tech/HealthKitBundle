<?php

declare(strict_types=1);

namespace App\Metrics;

use Redis;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Atomic page-view counter in Redis (short-lived connection per call; no socket kept in the worker).
 */
final readonly class DemoCounter
{
    private const KEY = 'demo:home_views';

    public function __construct(
        #[Autowire('%env(REDIS_URL)%')]
        private string $dsn,
    ) {
    }

    public function increment(): void
    {
        $this->withRedis(static fn (Redis $redis): mixed => $redis->incr(self::KEY));
    }

    public function read(): int
    {
        return (int) $this->withRedis(static fn (Redis $redis): mixed => $redis->get(self::KEY));
    }

    /**
     * @param callable(Redis): mixed $callback
     */
    private function withRedis(callable $callback): mixed
    {
        try {
            $redis = RedisAdapter::createConnection($this->dsn, ['timeout' => 0.5, 'read_timeout' => 0.5, 'lazy' => false]);
            if (!$redis instanceof Redis) {
                return null;
            }
            try {
                return $callback($redis);
            } finally {
                $redis->close();
            }
        } catch (Throwable) {
            return null;
        }
    }
}
