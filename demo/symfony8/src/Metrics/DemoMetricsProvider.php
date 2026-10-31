<?php

declare(strict_types=1);

namespace App\Metrics;

use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\MetricSample;
use Nowo\HealthKitBundle\Metrics\MetricsProviderInterface;
use Nowo\HealthKitBundle\Metrics\MetricType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Host-defined metrics provider (autoconfigured): build info and a page-view counter kept in Redis (INCR, atomic
 * across FrankenPHP workers). One Redis GET per scrape.
 */
final readonly class DemoMetricsProvider implements MetricsProviderInterface
{
    public function __construct(
        private DemoCounter $counter,
        #[Autowire('%env(default::APP_VERSION)%')]
        private string $version = '',
    ) {
    }

    public function collect(): iterable
    {
        yield new MetricFamily('demo_build_info', MetricType::Gauge, 'Demo build information', [
            new MetricSample(1, ['version' => '' === $this->version ? 'dev' : $this->version]),
        ]);
        yield MetricFamily::counter('demo_home_views_total', 'Demo home page views', $this->counter->read());
    }
}
