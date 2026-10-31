<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Probe;

use Nowo\HealthKitBundle\Probe\FrankenPhpProbe;
use Nowo\HealthKitBundle\Probe\HealthStatus;
use Nowo\HealthKitBundle\Runtime\FrankenPhpRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(FrankenPhpProbe::class)]
final class FrankenPhpProbeTest extends TestCase
{
    /**
     * @param array<string, string> $server
     */
    private function runtime(array $server): FrankenPhpRuntime
    {
        $stack = new RequestStack([new Request(server: $server)]);

        return new FrankenPhpRuntime($stack, 'frankenphp');
    }

    #[Test]
    public function reportsTheSnapshot(): void
    {
        $result = (new FrankenPhpProbe($this->runtime(['FRANKENPHP_MODE' => 'worker', 'FRANKENPHP_WORKER' => '1'])))->check(1.0);

        self::assertSame(HealthStatus::Ok, $result->status);
        self::assertSame('worker', $result->data['frankenphp_mode']);
        self::assertTrue($result->data['frankenphp_worker']);
    }

    #[Test]
    public function requireWorkerFailsInClassicMode(): void
    {
        $probe = new FrankenPhpProbe($this->runtime(['FRANKENPHP_MODE' => 'classic']), true);

        self::assertSame(HealthStatus::Error, $probe->check(1.0)->status);
        self::assertSame(HealthStatus::Ok, (new FrankenPhpProbe($this->runtime(['FRANKENPHP_WORKER' => 'true']), true))->check(1.0)->status);
    }
}
