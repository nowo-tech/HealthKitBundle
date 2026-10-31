<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Metrics;

use InvalidArgumentException;
use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\MetricSample;
use Nowo\HealthKitBundle\Metrics\MetricType;
use Nowo\HealthKitBundle\Metrics\PrometheusTextFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const INF;
use const NAN;

#[CoversClass(PrometheusTextFormatter::class)]
#[CoversClass(MetricFamily::class)]
#[CoversClass(MetricSample::class)]
final class PrometheusTextFormatterTest extends TestCase
{
    #[Test]
    public function rendersTheExpositionFormat(): void
    {
        $text = (new PrometheusTextFormatter())->format([
            MetricFamily::gauge('queue_pending', "Pending\\messages\non async", 3),
            MetricFamily::counter('ingest_total', 'Accepted', 1234567890123456.0),
            new MetricFamily('reject_total', MetricType::Counter, 'Rejected', [
                new MetricSample(2, ['reason' => 'quota']),
                new MetricSample(0.125, ['reason' => "bad\"value\\\nx", 'zone' => 'eu']),
            ]),
            new MetricFamily('special', MetricType::Untyped, 'Special values', [
                new MetricSample(NAN),
                new MetricSample(INF, ['s' => 'p']),
                new MetricSample(-INF, ['s' => 'n']),
                new MetricSample(-0.0, ['s' => 'z']),
            ]),
        ]);

        self::assertSame(
            "# HELP queue_pending Pending\\\\messages\\non async\n"
            . "# TYPE queue_pending gauge\n"
            . "queue_pending 3\n"
            . "# HELP ingest_total Accepted\n"
            . "# TYPE ingest_total counter\n"
            . "ingest_total 1234567890123456.0\n"
            . "# HELP reject_total Rejected\n"
            . "# TYPE reject_total counter\n"
            . "reject_total{reason=\"quota\"} 2\n"
            . "reject_total{reason=\"bad\\\"value\\\\\\nx\",zone=\"eu\"} 0.125\n"
            . "# HELP special Special values\n"
            . "# TYPE special untyped\n"
            . "special NaN\n"
            . "special{s=\"p\"} +Inf\n"
            . "special{s=\"n\"} -Inf\n"
            . "special{s=\"z\"} 0\n",
            $text,
        );
    }

    #[Test]
    public function emptyInputRendersNothing(): void
    {
        self::assertSame('', (new PrometheusTextFormatter())->format([]));
    }

    #[Test]
    public function rejectsInvalidMetricNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Prometheus metric name "1bad"');
        MetricFamily::gauge('1bad', 'x', 1);
    }

    #[Test]
    public function rejectsInvalidAndReservedLabelNames(): void
    {
        foreach (['bad-label', '__reserved'] as $label) {
            try {
                new MetricSample(1, [$label => 'x']);
                self::fail('Exception expected for ' . $label);
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($label, $e->getMessage());
            }
        }
    }
}
