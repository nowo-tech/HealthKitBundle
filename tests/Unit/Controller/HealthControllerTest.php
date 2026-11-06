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
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    private function pooled(CacheItemPoolInterface $pool, float $ttl = 5.0): HealthController
    {
        $probe = new StubProbe(function (): ProbeResult {
            ++$this->calls;

            return $this->healthy ? ProbeResult::ok() : ProbeResult::error('down');
        });
        $checker = new HealthChecker([new RegisteredProbe('db', $probe, ['ready'], true, 1.0)], $this->clock);

        return new HealthController($checker, new DetailsAccessPolicy(), $this->clock, '', false, $ttl, $pool);
    }

    #[Test]
    public function sharedPoolServesFreshProcessesUntilTheTtl(): void
    {
        $this->clock = new MockClock();
        $pool        = new ArrayAdapter();

        // Two controller instances = two PHP-FPM requests (fresh container, empty worker memo).
        self::assertSame(200, $this->pooled($pool)->ready($this->publicRequest())->getStatusCode());
        $this->setHealthy(false);
        self::assertSame(200, $this->pooled($pool)->ready($this->publicRequest())->getStatusCode());
        self::assertSame(1, $this->calls);

        $this->clock->sleep(5.0);
        self::assertSame(503, $this->pooled($pool)->ready($this->publicRequest())->getStatusCode());
        self::assertSame(2, $this->calls);

        // Corrupt or future-dated entries are ignored.
        foreach ([['at' => 'x'], 'garbage', ['at' => (float) $this->clock->now()->format('U.u') + 60, 'healthy' => true, 'status' => 'ok']] as $value) {
            $item = $pool->getItem(HealthController::CACHE_KEY);
            $pool->save($item->set($value));
            $this->pooled($pool)->ready($this->publicRequest());
        }
        self::assertSame(5, $this->calls);

        // TTL 0 never touches the pool.
        $this->pooled($pool, 0.0)->ready($this->publicRequest());
        $this->pooled($pool, 0.0)->ready($this->publicRequest());
        self::assertSame(7, $this->calls);
    }

    #[Test]
    public function brokenPoolDoesNotBreakReadiness(): void
    {
        $this->clock = new MockClock();
        $pool        = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('cache down'));

        self::assertSame(200, $this->pooled($pool)->ready($this->publicRequest())->getStatusCode());
        self::assertSame(1, $this->calls);
    }

    private function hidden(CacheItemPoolInterface $pool, bool $livePublic, bool $readyPublic): HealthController
    {
        $this->clock = new MockClock();
        $probe       = new StubProbe(function (): ProbeResult {
            ++$this->calls;

            return ProbeResult::ok();
        });
        $checker = new HealthChecker([new RegisteredProbe('db', $probe, ['ready', 'live'], true, 1.0)], $this->clock);
        $policy  = new DetailsAccessPolicy(['127.0.0.1', '::1'], 'details-token-0123456789');

        return new HealthController($checker, $policy, $this->clock, 'v1', true, 5.0, $pool, $livePublic, $readyPublic);
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertNotFound(callable $call): void
    {
        try {
            $call();
            self::fail('Expected a 404.');
        } catch (NotFoundHttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    #[Test]
    public function nonPublicReadinessIsNotFoundForUnknownCallersWithoutProbeOrCache(): void
    {
        $pool       = new ArrayAdapter();
        $controller = $this->hidden($pool, true, false);

        $this->assertNotFound(fn (): mixed => $controller->ready($this->publicRequest()));
        // Loopback behind a proxy (forwarding header present): the IP rule does not apply.
        $this->assertNotFound(static fn (): mixed => $controller->ready(Request::create('/health/ready', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'])));
        $this->assertNotFound(static fn (): mixed => $controller->ready(Request::create('/health/ready', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_AUTHORIZATION' => 'Bearer wrong-token-wrong-token'])));
        self::assertSame(0, $this->calls);
        self::assertFalse($pool->hasItem(HealthController::CACHE_KEY));

        $loopback = $controller->ready(Request::create('/health/ready', server: ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertSame(200, $loopback->getStatusCode());
        self::assertStringContainsString('"checks":{"db":', (string) $loopback->getContent());
        $bearer = $controller->ready(Request::create('/health/ready', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_AUTHORIZATION' => 'Bearer details-token-0123456789']));
        self::assertSame(200, $bearer->getStatusCode());
        self::assertSame(2, $this->calls);
        self::assertFalse($pool->hasItem(HealthController::CACHE_KEY));

        // live stays public.
        self::assertSame('{"status":"ok","version":"v1","client_ip":"203.0.113.9"}', $controller->live($this->publicRequest())->getContent());
    }

    #[Test]
    public function nonPublicLivenessIsNotFoundForUnknownCallers(): void
    {
        $controller = $this->hidden(new ArrayAdapter(), false, true);

        $this->assertNotFound(fn (): mixed => $controller->live($this->publicRequest()));
        self::assertSame(0, $this->calls);
        self::assertStringContainsString('"checks":{"db":', (string) $controller->live(Request::create('/health/live', server: ['REMOTE_ADDR' => '::1']))->getContent());
        self::assertSame(1, $this->calls);

        // ready stays public.
        self::assertSame('{"status":"ok"}', $controller->ready($this->publicRequest())->getContent());
    }
}
