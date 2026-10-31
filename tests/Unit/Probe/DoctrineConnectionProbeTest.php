<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Doctrine\DBAL\DriverManager;
use Nowo\HealthKitBundle\Probe\DoctrineConnectionProbe;
use Nowo\HealthKitBundle\Probe\HealthStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
