<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * REQ-RUNTIME-002: ONE kept-alive kernel, the services resetter never called (`http_kernel` driven directly, as a
 * FrankenPHP worker host that does not reset the kernel). Two callers alternate over /health/live, /health/ready and
 * /metrics: an anonymous public caller and an allowlisted (loopback) caller. Each response carries only its own
 * caller's data and memory stays flat.
 */
final class WorkerKeptAliveKernelTest extends KernelTestCase
{
    /** Iterations; each runs 3 requests (3 x 400 = 1,200 requests). */
    private const ITERATIONS = 400;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private const METRICS_TOKEN = 'metrics-token-REDACTED-TEST';

    #[Test]
    public function alternatingCallersNeverSeeEachOtherAndMemoryStaysFlat(): void
    {
        $kernel = $this->bootKernel([
            'version' => 'v1.2.3',
            'live'    => ['expose_client_ip' => true],
            'ready'   => ['public_cache_ttl' => 0],
            'metrics' => ['enabled' => true, 'token' => self::METRICS_TOKEN],
        ]);
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $admin = $i % 2 === 0;
            $ip    = $admin ? '127.0.0.1' : '203.0.113.' . (1 + $i % 200);
            $label = ($admin ? 'admin ' : 'public ') . $i;

            $live = $this->json($this->handle($http, '/health/live', ['REMOTE_ADDR' => $ip]));
            self::assertSame($ip, $live['client_ip'], $label . ': live carries this caller\'s IP');
            self::assertSame('v1.2.3', $live['version']);
            self::assertSame($admin, isset($live['checks']), $label . ': live details only for the allowlisted caller');

            $ready = $this->json($this->handle($http, '/health/ready', ['REMOTE_ADDR' => $ip]));
            self::assertSame('ok', $ready['status']);
            self::assertSame($admin, isset($ready['checks']), $label . ': ready details only for the allowlisted caller');
            self::assertArrayNotHasKey('client_ip', $ready);

            $bearer  = $admin ? ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_TOKEN] : [];
            $metrics = $this->handle($http, '/metrics', ['REMOTE_ADDR' => $ip] + $bearer);
            self::assertSame($admin ? 200 : 401, $metrics->getStatusCode(), $label . ': metrics token state not carried over');

            if ($i === 50) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat over many requests');
    }

    /**
     * @param array<string, string> $server
     */
    private function handle(HttpKernelInterface&TerminableInterface $http, string $uri, array $server): Response
    {
        $request  = Request::create($uri, 'GET', [], [], [], $server);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $http->terminate($request, $response);

        return $response;
    }

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
