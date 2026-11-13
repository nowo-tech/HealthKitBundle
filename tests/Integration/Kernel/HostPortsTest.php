<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use Nowo\HealthKitBundle\Health\HealthCheckerInterface;
use Nowo\HealthKitBundle\Metrics\MetricsCollectorInterface;
use Nowo\HealthKitBundle\Tests\Fixtures\HostHealthChecker;
use Nowo\HealthKitBundle\Tests\Fixtures\HostMetricsCollector;
use PHPUnit\Framework\Attributes\Test;

/**
 * REQ-ARCH-001 rule 1: the checker and collector have interfaces aliased in the container; a host replaces them.
 */
final class HostPortsTest extends KernelTestCase
{
    private const TOKEN = 'details-token-REDACTED-TEST';

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'ready'   => ['public_cache_ttl' => 0],
            'details' => ['token' => self::TOKEN],
            'metrics' => ['enabled' => true, 'token' => 'metrics-token-REDACTED-TEST', 'probe_metrics' => false],
        ];
    }

    #[Test]
    public function hostOverridesTheCheckerAlias(): void
    {
        $kernel = $this->bootKernel($this->config(), [], null, [HealthCheckerInterface::class => HostHealthChecker::class]);

        $ready = $this->json($this->request($kernel, '/health/ready', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]));

        self::assertSame('degraded', $ready['status']);
        self::assertIsArray($ready['checks']);
        self::assertSame(['host_owned'], array_keys($ready['checks']));
    }

    #[Test]
    public function hostOverridesTheCollectorAlias(): void
    {
        $kernel = $this->bootKernel($this->config(), [], null, [MetricsCollectorInterface::class => HostMetricsCollector::class]);

        $scrape = $this->request($kernel, '/metrics', ['HTTP_AUTHORIZATION' => 'Bearer metrics-token-REDACTED-TEST']);

        self::assertSame(200, $scrape->getStatusCode());
        self::assertStringContainsString('host_collector_up 1', (string) $scrape->getContent());
        self::assertStringNotContainsString('health_kit_metrics_provider_up', (string) $scrape->getContent());
    }
}
