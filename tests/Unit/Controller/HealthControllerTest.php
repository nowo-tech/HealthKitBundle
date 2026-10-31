<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Controller;

use Nowo\HealthKitBundle\Controller\HealthController;
use Nowo\HealthKitBundle\Health\HealthChecker;
use Nowo\HealthKitBundle\Health\RegisteredProbe;
use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Nowo\HealthKitBundle\Probe\ProbeResult;
use Nowo\HealthKitBundle\Tests\Fixtures\StubProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(HealthController::class)]
final class HealthControllerTest extends TestCase
{
    private MockClock $clock;
    private bool $healthy = true;
    private int $calls    = 0;

    private function controller(float $ttl = 5.0, string $version = '', bool $exposeIp = false): HealthController
    {
        $this->clock = new MockClock();
        $probe       = new StubProbe(function (): ProbeResult {
            ++$this->calls;

            return $this->healthy ? ProbeResult::ok() : ProbeResult::error('down');
        });
        $checker = new HealthChecker([new RegisteredProbe('db', $probe, ['ready', 'live'], true, 1.0)], $this->clock);

        return new HealthController($checker, new DetailsAccessPolicy(), $this->clock, $version, $exposeIp, $ttl);
    }

    private function setHealthy(bool $healthy): void
    {
        $this->healthy = $healthy;
    }

    private function publicRequest(): Request
    {
        return Request::create('/health/ready', server: ['REMOTE_ADDR' => '203.0.113.9']);
    }

    #[Test]
    public function publicReadinessIsMemoisedForTheTtl(): void
    {
        $controller = $this->controller(5.0);

        self::assertSame(200, $controller->ready($this->publicRequest())->getStatusCode());
        $this->setHealthy(false);
        self::assertSame(200, $controller->ready($this->publicRequest())->getStatusCode());
        self::assertSame(1, $this->calls);

        $this->clock->sleep(5);
        $response = $controller->ready($this->publicRequest());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":"error"}', $response->getContent());
        self::assertSame(2, $this->calls);
    }

    #[Test]
    public function zeroTtlDisablesTheMemoAndDetailCallersBypassIt(): void
    {
        $controller = $this->controller(0.0);
        $controller->ready($this->publicRequest());
        $controller->ready($this->publicRequest());
        self::assertSame(2, $this->calls);

        $detailed = $controller->ready(Request::create('/health/ready', server: ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertStringContainsString('"checks":{"db":', (string) $detailed->getContent());
        self::assertSame(3, $this->calls);
    }

    #[Test]
    public function liveAddsVersionAndClientIpAndSetsNoStoreHeaders(): void
    {
        $response = $this->controller(5.0, 'v9.9.9', true)->live($this->publicRequest());

        self::assertSame('{"status":"ok","version":"v9.9.9","client_ip":"203.0.113.9"}', $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        $this->setHealthy(false);
        self::assertSame(503, $this->controller()->live($this->publicRequest())->getStatusCode());
    }
}
