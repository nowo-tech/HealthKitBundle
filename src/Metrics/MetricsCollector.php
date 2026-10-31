<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_values;
use function strrchr;
use function substr;

/**
 * Aggregates the families of every tagged {@see MetricsProviderInterface}.
 *
 * Providers run in tag priority order, once per scrape. A failing provider is logged (class name and exception
 * class only) and reported through `health_kit_metrics_provider_up{provider="…"} 0`; families sharing a name and
 * type are merged, a name reused with another type is dropped (the first one wins).
 */
final readonly class MetricsCollector
{
    private LoggerInterface $logger;

    /**
     * @param iterable<MetricsProviderInterface> $providers tagged providers
     * @param LoggerInterface|null $logger logger for provider failures
     */
    public function __construct(
        private iterable $providers,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Collects every family.
     *
     * @return list<MetricFamily> merged families, followed by the provider status family
     */
    public function collect(): array
    {
        /** @var array<string, MetricFamily> $families */
        $families = [];
        $status   = [];

        foreach ($this->providers as $provider) {
            $name = $this->providerName($provider);
            try {
                $collected = [];
                foreach ($provider->collect() as $family) {
                    $collected[] = $family;
                }
            } catch (Throwable $e) {
                $this->logger->warning('Metrics provider "{provider}" failed.', [
                    'provider'    => $provider::class,
                    'error_class' => $e::class,
                ]);
                $status[] = new MetricSample(0.0, ['provider' => $name]);

                continue;
            }
            $status[] = new MetricSample(1.0, ['provider' => $name]);

            foreach ($collected as $family) {
                $existing = $families[$family->name] ?? null;
                if ($existing === null) {
                    $families[$family->name] = $family;
                } elseif ($existing->type === $family->type) {
                    $families[$family->name] = new MetricFamily($existing->name, $existing->type, $existing->help, [...$existing->samples, ...$family->samples]);
                } else {
                    $this->logger->warning('Metric "{metric}" from "{provider}" dropped: type mismatch.', [
                        'metric'   => $family->name,
                        'provider' => $provider::class,
                    ]);
                }
            }
        }

        $out = array_values($families);
        if ($status !== []) {
            $out[] = new MetricFamily('health_kit_metrics_provider_up', MetricType::Gauge, 'Whether the metrics provider answered the last scrape (1) or failed (0)', $status);
        }

        return $out;
    }

    /**
     * Short class name used as the `provider` label.
     *
     * @param MetricsProviderInterface $provider provider
     *
     * @return string short class name
     */
    private function providerName(MetricsProviderInterface $provider): string
    {
        $class = strrchr($provider::class, '\\');

        return $class === false ? $provider::class : substr($class, 1);
    }
}
