<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Nowo\HealthKitBundle\Probe\HealthStatus;
use Nowo\HealthKitBundle\Probe\RedisProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface as PredisClientInterface;
use Predis\Response\Status;
use RuntimeException;
use stdClass;
use Stringable;
use Throwable;

#[CoversClass(RedisProbe::class)]
final class RedisProbeTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, HealthStatus}>
     */
    public static function pongs(): iterable
    {
        yield 'phpredis true' => [true, HealthStatus::Ok];
        yield 'phpredis +PONG' => ['+PONG', HealthStatus::Ok];
        yield 'predis status' => [new class implements Stringable {
            public function __toString(): string
            {
                return 'PONG';
            }
        }, HealthStatus::Ok];
        yield 'false' => [false, HealthStatus::Error];
        yield 'other string' => ['LOADING', HealthStatus::Error];
    }

    #[Test]
    #[DataProvider('pongs')]
    public function interpretsPingAnswers(mixed $pong, HealthStatus $expected): void
    {
        $client = new class($pong) {
            public function __construct(private readonly mixed $pong)
            {
            }

            public function ping(): mixed
            {
                return $this->pong;
            }
        };

        self::assertSame($expected, (new RedisProbe($client))->check(1.0)->status);
    }

    #[Test]
    public function opensAndClosesADsnConnectionWithTimeouts(): void
    {
        $client = new class {
            public bool $closed = false;

            public function ping(): bool
            {
                return true;
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        $seen  = [];
        $probe = new RedisProbe(null, 'redis://cache:6379', static function (string $dsn, array $options) use ($client, &$seen): object {
            $seen = [$dsn, $options];

            return $client;
        });

        self::assertSame(HealthStatus::Ok, $probe->check(0.75)->status);
        self::assertTrue($client->closed);
        self::assertSame('redis://cache:6379', $seen[0]);
        self::assertSame(['timeout' => 0.75, 'read_timeout' => 0.75, 'retry_interval' => 0, 'lazy' => false], $seen[1]);
    }

    #[Test]
    public function closesTheOwnedConnectionWhenPingThrows(): void
    {
        $client = new class {
            public bool $closed = false;

            public function ping(): never
            {
                throw new RuntimeException('read error on connection');
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        $probe = new RedisProbe(null, 'redis://cache', static fn (): object => $client);

        try {
            $probe->check(1.0);
            self::fail('Exception expected.');
        } catch (RuntimeException) {
            self::assertTrue($client->closed);
        }
    }

    #[Test]
    public function reportsMissingDsnAndUnsupportedClients(): void
    {
        self::assertSame('No Redis DSN configured.', (new RedisProbe())->check(1.0)->message);
        self::assertSame('No Redis DSN configured.', (new RedisProbe(null, ''))->check(1.0)->message);
        self::assertSame('The Redis client does not support PING.', (new RedisProbe(new stdClass()))->check(1.0)->message);
    }

    #[Test]
    public function defaultFactoryFailsFastOnAnUnreachableServer(): void
    {
        $this->expectException(Throwable::class);
        (new RedisProbe(null, 'redis://127.0.0.1:1'))->check(0.2);
    }

    #[Test]
    public function predisClientsAreDisconnectedNotClosed(): void
    {
        $client = $this->createMock(PredisClientInterface::class);
        $client->method('__call')->willReturnCallback(static fn (string $method): mixed => $method === 'ping' ? new Status('PONG') : throw new RuntimeException('Command `CLOSE` is not a registered Redis command.'));
        $client->expects(self::once())->method('disconnect');

        self::assertSame(HealthStatus::Ok, (new RedisProbe(null, 'redis://cache', static fn (): object => $client))->check(0.5)->status);
    }

    #[Test]
    public function aFailingCloseNeverMasksTheOutcome(): void
    {
        $client = new class {
            public function ping(): bool
            {
                return true;
            }

            public function close(): never
            {
                throw new RuntimeException('close failed');
            }
        };

        self::assertSame(HealthStatus::Ok, (new RedisProbe(null, 'redis://cache', static fn (): object => $client))->check(0.5)->status);
    }

    #[Test]
    public function ownedClientWithoutPingIsClosedToo(): void
    {
        $client = new class {
            public bool $closed = false;

            public function close(): void
            {
                $this->closed = true;
            }
        };

        self::assertSame('The Redis client does not support PING.', (new RedisProbe(null, 'redis://cache', static fn (): object => $client))->check(0.5)->message);
        self::assertTrue($client->closed);
    }
}
