<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * A Prometheus metric family: name, type, help text and samples.
 */
final readonly class MetricFamily
{
    /**
     * @param string $name metric name (`[a-zA-Z_:][a-zA-Z0-9_:]*`)
     * @param MetricType $type family type
     * @param string $help help text (escaped by the formatter)
     * @param list<MetricSample> $samples samples
     *
     * @throws InvalidArgumentException when the name is not a valid Prometheus metric name or two samples share a label set
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $help,
        public array $samples,
    ) {
        if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*$/D', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Prometheus metric name "%s".', $name));
        }
        $seen = [];
        foreach ($samples as $sample) {
            $key = $sample->seriesKey();
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf('Duplicate label set in Prometheus metric "%s".', $name));
            }
            $seen[$key] = true;
        }
    }

    /**
     * Gauge family with a single unlabelled sample.
     *
     * @param string $name metric name
     * @param string $help help text
     * @param float $value value
     *
     * @return self gauge family
     */
    public static function gauge(string $name, string $help, float $value): self
    {
        return new self($name, MetricType::Gauge, $help, [new MetricSample($value)]);
    }

    /**
     * Counter family with a single unlabelled sample.
     *
     * @param string $name metric name (by convention ending in `_total`)
     * @param string $help help text
     * @param float $value value
     *
     * @return self counter family
     */
    public static function counter(string $name, string $help, float $value): self
    {
        return new self($name, MetricType::Counter, $help, [new MetricSample($value)]);
    }
}
