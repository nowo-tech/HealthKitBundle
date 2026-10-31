<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * One sample (label set + value) of a {@see MetricFamily}.
 */
final readonly class MetricSample
{
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
            if (preg_match('/^[a-zA-Z_]\w*$/', (string) $name) !== 1 || str_starts_with((string) $name, '__')) {
                throw new InvalidArgumentException(sprintf('Invalid Prometheus label name "%s".', $name));
            }
        }
    }
}
