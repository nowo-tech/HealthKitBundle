<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use InvalidArgumentException;

use function ksort;
use function preg_match;
use function serialize;
use function sprintf;
use function str_starts_with;

/**
 * One sample (label set + value) of a {@see MetricFamily}.
 */
final readonly class MetricSample
{
    /**
     * Series key of the label set (order-insensitive), used to reject duplicate series in a family.
     *
     * @return string serialized, sorted label set
     */
    public function seriesKey(): string
    {
        $labels = $this->labels;
        ksort($labels);

        return serialize($labels);
    }

    /**
     * @param float $value sample value (NaN and ±Inf are allowed)
     * @param array<string, string> $labels label name => value
     *
     * @throws InvalidArgumentException when a label name is not a valid Prometheus label name
     */
    public function __construct(
        public float $value,
        public array $labels = [],
    ) {
        foreach ($labels as $name => $_) {
            if (preg_match('/^[a-zA-Z_]\w*$/D', (string) $name) !== 1 || str_starts_with((string) $name, '__')) {
                throw new InvalidArgumentException(sprintf('Invalid Prometheus label name "%s".', $name));
            }
        }
    }
}
