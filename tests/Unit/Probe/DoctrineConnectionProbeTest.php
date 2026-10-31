<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Nowo\HealthKitBundle\Probe\DoctrineConnectionProbe;
use Nowo\HealthKitBundle\Probe\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(DoctrineConnectionProbe::class)]
final class DoctrineConnectionProbeTest extends TestCase
{
    #[Test]
    public function pingsTheConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        self::assertSame(HealthStatus::Ok, (new DoctrineConnectionProbe($connection))->check(1.0)->status);
    }

    #[Test]
    public function propagatesConnectionErrors(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/nonexistent-dir/health.sqlite']);

        $this->expectException(Throwable::class);
        (new DoctrineConnectionProbe($connection))->check(1.0);
    }

    #[Test]
    public function mysqlSelectCarriesTheTimeoutHint(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::once())->method('executeQuery')
            ->with('SELECT /*+ MAX_EXECUTION_TIME(1500) */ 1')
            ->willThrowException(new RuntimeException('stop after the query was built'));

        $this->expectExceptionMessage('stop after the query was built');
        (new DoctrineConnectionProbe($connection))->check(1.5);
    }
}
