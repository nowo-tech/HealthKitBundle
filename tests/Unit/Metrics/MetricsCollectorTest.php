<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Metrics;

use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\MetricSample;
use Nowo\HealthKitBundle\Metrics\MetricsCollector;
use Nowo\HealthKitBundle\Metrics\MetricsProviderInterface;
use Nowo\HealthKitBundle\Metrics\MetricType;
use Nowo\HealthKitBundle\Tests\Fixtures\CounterProvider;
use Nowo\HealthKitBundle\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use const JSON_THROW_ON_ERROR;

#[CoversClass(MetricsCollector::class)]
final class MetricsCollectorTest extends TestCase
{
    #[Test]
    public function mergesFamiliesAndReportsProviderStatus(): void
    {
        $logger  = $this->logger();
        $failing = new class implements MetricsProviderInterface {
            public function collect(): iterable
            {
                throw new RuntimeException('redis://user:secret@host down');
            }
        };
        $more = new class implements MetricsProviderInterface {
            public function collect(): iterable
            {
                return [
                    new MetricFamily('app_ingest_ack_total', MetricType::Counter, 'ignored help', [new MetricSample(1, ['src' => 'b'])]),
                    MetricFamily::gauge('app_ingest_ack_total', 'type clash', 9),
                    MetricFamily::gauge('queue_depth', 'Depth', 7),
                ];
            }
        };

        $families = (new MetricsCollector([new CounterProvider(), $failing, $more], $logger))->collect();

        self::assertSame(['app_ingest_ack_total', 'queue_depth', 'health_kit_metrics_provider_up'], array_map(static fn (MetricFamily $f): string => $f->name, $families));
        self::assertCount(2, $families[0]->samples);
        self::assertSame('Accepted ingest requests', $families[0]->help);
        $status = array_map(static fn (MetricSample $s): array => [$s->labels['provider'], $s->value], $families[2]->samples);
        self::assertSame('CounterProvider', $status[0][0]);
        self::assertSame(1.0, $status[0][1]);
        self::assertSame(0.0, $status[1][1]);
        self::assertSame(1.0, $status[2][1]);
        self::assertCount(2, $logger->records);
        self::assertStringNotContainsString('secret', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function noProvidersMeansNoFamilies(): void
    {
        self::assertSame([], (new MetricsCollector([]))->collect());
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    #[Test]
    public function duplicateSeriesAcrossProvidersKeepTheFirst(): void
    {
        $logger = $this->logger();
        $a      = new class implements MetricsProviderInterface {
            public function collect(): iterable
            {
                yield new MetricFamily('jobs', MetricType::Gauge, 'Jobs', [new MetricSample(1, ['q' => 'a'])]);
            }
        };
        $b = new class implements MetricsProviderInterface {
            public function collect(): iterable
            {
                yield new MetricFamily('jobs', MetricType::Gauge, 'Jobs', [new MetricSample(9, ['q' => 'a']), new MetricSample(2, ['q' => 'b'])]);
            }
        };

        $jobs = (new MetricsCollector([$a, $b], $logger))->collect()[0];

        self::assertSame([[['q' => 'a'], 1.0], [['q' => 'b'], 2.0]], array_map(static fn (MetricSample $s): array => [$s->labels, $s->value], $jobs->samples));
        self::assertCount(1, $logger->records);
    }
}
