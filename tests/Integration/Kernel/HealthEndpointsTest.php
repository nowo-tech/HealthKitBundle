<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use Nowo\HealthKitBundle\Tests\Fixtures\AutoNamedProbe;
use Nowo\HealthKitBundle\Tests\Fixtures\CounterProvider;
use Nowo\HealthKitBundle\Tests\Fixtures\MutableTokenProvider;
use Nowo\HealthKitBundle\Tests\Fixtures\QueueProbe;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end behaviour of /health/live, /health/ready and /metrics in a real kernel.
 */
final class HealthEndpointsTest extends KernelTestCase
{
    private const DETAILS_TOKEN = 'details-token-REDACTED-TEST';
    private const METRICS_TOKEN = 'metrics-token-REDACTED-TEST';

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'version' => 'v1.2.3',
            'live'    => ['expose_client_ip' => true],
            'ready'   => ['public_cache_ttl' => 0],
            'details' => ['token' => self::DETAILS_TOKEN, 'allowed_ips' => ['10.0.0.0/8', '::1']],
            'probes'  => [
                'doctrine'  => ['enabled' => true],
                'messenger' => ['enabled' => true, 'transports' => ['async' => ['warn_at' => 10]]],
            ],
            'metrics' => ['enabled' => true, 'token' => self::METRICS_TOKEN],
        ];
    }

    private function boot(): HealthKitTestKernel
    {
        return $this->bootKernel(
            $this->config(),
            [
                'doctrine'  => ['dbal' => ['url' => 'sqlite:///:memory:']],
                'framework' => ['messenger' => ['transports' => ['async' => 'in-memory://']]],
            ],
            null,
            ['app.queue_probe' => QueueProbe::class, 'app.auto_probe' => AutoNamedProbe::class, 'app.counter_provider' => CounterProvider::class],
            true,
        );
    }

    #[Test]
    public function publicCallersOnlySeeTheStatus(): void
    {
        $kernel = $this->boot();

        $live = $this->request($kernel, '/health/live');
        self::assertSame(200, $live->getStatusCode());
        self::assertSame(['status' => 'ok', 'version' => 'v1.2.3', 'client_ip' => '203.0.113.9'], $this->json($live));
        self::assertStringContainsString('no-store', (string) $live->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $live->headers->get('Cache-Control'));

        $ready = $this->request($kernel, '/health/ready');
        self::assertSame(200, $ready->getStatusCode());
        self::assertSame(['status' => 'degraded'], $this->json($ready));
        self::assertStringContainsString('no-store', (string) $ready->headers->get('Cache-Control'));
    }

    #[Test]
    public function allowlistedAndTokenCallersSeeDetails(): void
    {
        $kernel = $this->boot();

        $ready = $this->json($this->request($kernel, '/health/ready', ['REMOTE_ADDR' => '10.1.2.3']));
        self::assertSame('degraded', $ready['status']);
        self::assertIsArray($ready['checks']);
        self::assertSame(['auto_named', 'doctrine.default', 'messenger.async', 'payments_api'], array_keys($ready['checks']));
        self::assertSame('ok', $ready['checks']['doctrine.default']['status']);
        self::assertSame('degraded', $ready['checks']['messenger.async']['status']);
        self::assertSame('degraded', $ready['checks']['payments_api']['status']);
        self::assertFalse($ready['checks']['payments_api']['critical']);
        self::assertSame('Payments API unreachable.', $ready['checks']['payments_api']['message']);
        self::assertArrayHasKey('duration_ms', $ready['checks']['doctrine.default']);

        $live = $this->json($this->request($kernel, '/health/live', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::DETAILS_TOKEN]));
        self::assertSame('ok', $live['checks']['frankenphp']['status']);
        self::assertSame('classic', $live['checks']['frankenphp']['data']['frankenphp_mode']);

        $wrong = $this->json($this->request($kernel, '/health/live', ['HTTP_AUTHORIZATION' => 'Bearer wrong-token-wrong-token']));
        self::assertArrayNotHasKey('checks', $wrong);
        $query = $this->json($this->request($kernel, '/health/live?token=' . self::DETAILS_TOKEN));
        self::assertArrayNotHasKey('checks', $query);
    }

    #[Test]
    public function metricsRequireTheTokenAndAggregateProviders(): void
    {
        $kernel = $this->boot();

        $anonymous = $this->request($kernel, '/metrics');
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertSame('Bearer realm="metrics"', $anonymous->headers->get('WWW-Authenticate'));
        self::assertSame(401, $this->request($kernel, '/metrics?token=' . self::METRICS_TOKEN)->getStatusCode());

        $scrape = $this->request($kernel, '/metrics', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_TOKEN]);
        self::assertSame(200, $scrape->getStatusCode());
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $scrape->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $scrape->headers->get('Cache-Control'));
        $body = (string) $scrape->getContent();
        self::assertStringContainsString("app_ingest_ack_total 42\n", $body);
        self::assertStringContainsString('health_probe_status{probe="doctrine.default"} 1', $body);
        self::assertStringContainsString('health_probe_status{probe="payments_api"} 0.5', $body);
        self::assertStringContainsString('health_probe_data{probe="payments_api",key="latency_ms"} 12.5', $body);
        self::assertStringContainsString('health_kit_metrics_provider_up{provider="CounterProvider"} 1', $body);
        self::assertStringNotContainsString(self::METRICS_TOKEN, $body);
    }

    #[Test]
    public function metricsAreNotFoundWhenDisabledAndUnavailableWithoutAccessConfig(): void
    {
        $disabled = $this->bootKernel([]);
        self::assertSame(404, $this->request($disabled, '/metrics')->getStatusCode());

        $unconfigured = $this->bootKernel(['metrics' => ['enabled' => true, 'token' => 'too-short']]);
        self::assertSame(503, $this->request($unconfigured, '/metrics', ['HTTP_AUTHORIZATION' => 'Bearer too-short'])->getStatusCode());
    }

    #[Test]
    public function defaultConfigurationKeepsDetailsForLoopbackOnly(): void
    {
        $kernel = $this->bootKernel([]);

        self::assertSame(['status' => 'ok'], $this->json($this->request($kernel, '/health/live')));
        self::assertSame(['status' => 'ok'], $this->json($this->request($kernel, '/health/ready')));

        $loopback = $this->json($this->request($kernel, '/health/live', ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertSame(['frankenphp'], array_keys($loopback['checks']));
        $ready = $this->json($this->request($kernel, '/health/ready', ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertSame('ok', $ready['status']);
        self::assertSame([], $ready['checks']);
    }

    #[Test]
    public function failingCriticalProbeAnswers503(): void
    {
        $kernel = $this->bootKernel([
            'ready'  => ['public_cache_ttl' => 60],
            'probes' => ['redis' => ['enabled' => true, 'dsn' => 'redis://127.0.0.1:1', 'timeout' => 0.2]],
        ]);

        $first = $this->request($kernel, '/health/ready');
        self::assertSame(503, $first->getStatusCode());
        self::assertSame(['status' => 'error'], $this->json($first));
        // Memoised public answer (same worker).
        self::assertSame(503, $this->request($kernel, '/health/ready')->getStatusCode());

        $detail = $this->json($this->request($kernel, '/health/ready', ['REMOTE_ADDR' => '::1']));
        self::assertSame('error', $detail['checks']['redis']['status']);
        self::assertIsString($detail['checks']['redis']['message']);
    }

    #[Test]
    public function headRequestsAreAccepted(): void
    {
        $kernel  = $this->bootKernel([]);
        $request = Request::create('/health/live', 'HEAD', server: ['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame(200, $kernel->handle($request)->getStatusCode());
    }

    #[Test]
    public function runtimeTokenProvidersAreReadPerRequestWithoutReboot(): void
    {
        $kernel = $this->bootKernel(
            [
                'details' => ['token_provider' => 'app.settings_token'],
                'metrics' => ['enabled' => true, 'token_provider' => 'app.settings_token'],
            ],
            [],
            null,
            ['app.settings_token' => MutableTokenProvider::class],
        );
        $testContainer = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $testContainer);
        $provider = $testContainer->get('app.settings_token');
        self::assertInstanceOf(MutableTokenProvider::class, $provider);

        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::METRICS_TOKEN];
        self::assertSame(503, $this->request($kernel, '/metrics', $bearer)->getStatusCode());
        self::assertArrayNotHasKey('checks', $this->json($this->request($kernel, '/health/live', $bearer)));

        $provider->token = self::METRICS_TOKEN;
        self::assertSame(200, $this->request($kernel, '/metrics', $bearer)->getStatusCode());
        self::assertArrayHasKey('checks', $this->json($this->request($kernel, '/health/live', $bearer)));

        $provider->token = 'rotated-token-REDACTED-TEST';
        self::assertSame(401, $this->request($kernel, '/metrics', $bearer)->getStatusCode());
    }

    #[Test]
    public function bothTokenSourcesFailTheCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('mutually exclusive');
        $this->bootKernel(['metrics' => ['token' => self::METRICS_TOKEN, 'token_provider' => 'app.settings_token']]);
    }
}
