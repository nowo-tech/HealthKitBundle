<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

use Closure;
use Predis\ClientInterface as PredisClientInterface;
use Stringable;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Throwable;

use function is_callable;
use function is_string;
use function method_exists;
use function str_contains;
use function strtoupper;

/**
 * Sends `PING` to Redis, either through an existing client service (`\Redis`, `\Relay\Relay`, Predis) or through a
 * short-lived connection opened from a DSN with connect and read timeouts.
 *
 * A DSN connection is opened and closed on every check, so no socket is kept between requests in worker mode.
 */
final readonly class RedisProbe implements HealthProbeInterface
{
    private Closure $connectionFactory;

    /**
     * @param object|null $client client service exposing `ping()`; takes precedence over `$dsn`
     * @param string|null $dsn Redis DSN (`redis://host:6379`) used when no client is given
     * @param Closure|null $connectionFactory `fn (string $dsn, array $options): object`; defaults to {@see RedisAdapter::createConnection()}
     */
    public function __construct(
        private ?object $client = null,
        private ?string $dsn = null,
        ?Closure $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory ?? static fn (string $dsn, array $options): object => RedisAdapter::createConnection($dsn, $options);
    }

    /**
     * Pings Redis.
     *
     * @param float $timeout connect and read timeout (seconds) for DSN connections
     *
     * @return ProbeResult ok on PONG, error otherwise
     */
    public function check(float $timeout): ProbeResult
    {
        $client = $this->client;
        $owned  = false;
        if ($client === null) {
            if ($this->dsn === null || $this->dsn === '') {
                return ProbeResult::error('No Redis DSN configured.');
            }
            $client = ($this->connectionFactory)($this->dsn, [
                'timeout'        => $timeout,
                'read_timeout'   => $timeout,
                'retry_interval' => 0,
                'lazy'           => false,
            ]);
            $owned = true;
        }

        try {
            $ping = [$client, 'ping'];
            if (!is_callable($ping)) {
                return ProbeResult::error('The Redis client does not support PING.');
            }
            $pong = $ping();
        } finally {
            if ($owned) {
                $this->disconnect($client);
            }
        }

        if ($pong === true || ((is_string($pong) || $pong instanceof Stringable) && str_contains(strtoupper((string) $pong), 'PONG'))) {
            return ProbeResult::ok();
        }

        return ProbeResult::error('Redis did not answer PONG.');
    }

    /**
     * Closes a connection opened by this probe, with the method of its client type (Predis `disconnect()`, phpredis
     * and Relay `close()`); a failure while closing never masks the probe outcome (audit BX L4).
     *
     * @param object $client client opened from the DSN
     */
    private function disconnect(object $client): void
    {
        try {
            if ($client instanceof PredisClientInterface) {
                $client->disconnect();
            } elseif (method_exists($client, 'close')) {
                $client->close();
            }
        } catch (Throwable) {
            // Closing is best effort; the socket is released with the object anyway.
        }
    }
}
