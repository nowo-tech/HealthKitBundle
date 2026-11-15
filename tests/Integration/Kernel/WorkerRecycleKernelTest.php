<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use Nowo\HealthKitBundle\Runtime\WorkerRecycleException;
use Nowo\HealthKitBundle\Tests\Fixtures\ConnectionController;
use Nowo\HealthKitBundle\Tests\Fixtures\LostConnectionException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * `runtime.worker_recycle` and `runtime.dev_collector_reset` in a real kernel kept alive across requests (the services
 * resetter never runs), as a FrankenPHP worker with `FRANKENPHP_RESET_KERNEL=false`.
 */
final class WorkerRecycleKernelTest extends KernelTestCase
{
    private const WORKER = ['APP_RUNTIME_MODE' => 'web=1&worker=1'];

    private const METRICS_TOKEN = 'metrics-token-REDACTED-TEST';

    private function http(bool $enabled = true): HttpKernelInterface&TerminableInterface
    {
        $kernel = $this->bootKernel(
            [
                'metrics' => ['enabled' => true, 'token' => self::METRICS_TOKEN],
                'ready'   => ['public_cache_ttl' => 0],
                'runtime' => [
                    'dev_collector_reset' => true,
                    'worker_recycle'      => [
                        'enabled'           => $enabled,
                        'redis'             => true,
                        'exception_classes' => [LostConnectionException::class],
                        'state_pool'        => 'cache.app',
                        'probe'             => ['enabled' => $enabled],
                    ],
                ],
            ],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('@NowoHealthKitBundle/config/routes/health.yaml');
                $routes->import('@NowoHealthKitBundle/config/routes/metrics.yaml');
                $routes->add('connection', '/connection')->controller('app.connection_controller');
            },
            services: ['app.connection_controller' => ConnectionController::class],
        );
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        return $http;
    }

    /**
     * Sends the response first, then terminates: as Symfony's FrankenPhpWorkerRunner does.
     *
     * @param array<string, string> $server
     *
     * @return array{Response, WorkerRecycleException|null}
     */
    private function serve(HttpKernelInterface&TerminableInterface $http, string $uri, array $server = []): array
    {
        $request  = Request::create($uri, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', ...$server]);
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        try {
            $http->terminate($request, $response);
        } catch (WorkerRecycleException $e) {
            return [$response, $e];
        }

        return [$response, null];
    }

    #[Test]
    public function aLostConnectionEndsTheWorkerAfterTheFullResponse(): void
    {
        $http = $this->http();

        [$response, $recycle] = $this->serve($http, '/connection?fail=lost', self::WORKER);
        self::assertSame(500, $response->getStatusCode(), 'the failing request still gets its complete error response');
        self::assertInstanceOf(WorkerRecycleException::class, $recycle);

        [, $recycle] = $this->serve($http, '/connection?fail=other', self::WORKER);
        self::assertNull($recycle, 'unrelated errors never recycle');

        [$response, $recycle] = $this->serve($http, '/connection?fail=caught', self::WORKER);
        self::assertSame('degraded', $response->getContent());
        self::assertInstanceOf(WorkerRecycleException::class, $recycle, 'host code can ask for a recycle through the port');

        [$response] = $this->serve($http, '/health/ready');
        $checks     = $this->json($response)['checks'];
        self::assertIsArray($checks);
        self::assertSame('degraded', $checks['runtime.worker_recycle']['status']);
        self::assertSame(2, $checks['runtime.worker_recycle']['data']['recycles_total']);

        [$metrics] = $this->serve($http, '/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_TOKEN]);
        self::assertStringContainsString("health_kit_worker_recycles_total 2\n", (string) $metrics->getContent());
    }

    #[Test]
    public function outsideWorkerModeItIsANoOpAndMemoryStaysFlat(): void
    {
        $http = $this->http();

        $baseline = 0;
        for ($i = 0; $i < 600; ++$i) {
            [$response, $recycle] = $this->serve($http, '/connection?fail=lost');
            self::assertSame(500, $response->getStatusCode());
            self::assertNull($recycle, 'php-fpm / classic mode: nothing thrown');

            // The mark never leaks into the next request, even when that one runs in worker mode.
            [$response, $recycle] = $this->serve($http, '/connection', self::WORKER);
            self::assertSame('ok', $response->getContent());
            self::assertNull($recycle);

            if ($i === 50) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline);

        [$response] = $this->serve($http, '/health/ready');
        self::assertSame(0, $this->json($response)['checks']['runtime.worker_recycle']['data']['recycles_total']);
    }

    #[Test]
    public function disabledByDefaultChangesNothing(): void
    {
        $kernel = $this->bootKernel([]);
        self::assertFalse($kernel->getContainer()->has('nowo_health_kit.runtime.worker_recycler'));

        $http        = $this->http(false);
        [, $recycle] = $this->serve($http, '/health/live', self::WORKER);
        self::assertNull($recycle);
    }
}
