<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use function abs;
use function floor;
use function implode;
use function is_infinite;
use function is_nan;
use function str_replace;
use function var_export;

/**
 * Renders metric families in the Prometheus text exposition format 0.0.4.
 *
 * Help text escapes `\` and line feeds; label values escape `\`, `"` and line feeds; values render NaN / +Inf /
 * -Inf, integers without a decimal part and other floats with full precision.
 */
final class PrometheusTextFormatter
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * Renders the families.
     *
     * @param iterable<MetricFamily> $families families to render
     *
     * @return string exposition text, ending with a line feed (empty string when there is nothing to render)
     */
    public function format(iterable $families): string
    {
        $lines = [];
        foreach ($families as $family) {
            $lines[] = '# HELP ' . $family->name . ' ' . str_replace(['\\', "\n"], ['\\\\', '\\n'], $family->help);
            $lines[] = '# TYPE ' . $family->name . ' ' . $family->type->value;
            foreach ($family->samples as $sample) {
                $lines[] = $family->name . $this->formatLabels($sample->labels) . ' ' . $this->formatValue($sample->value);
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Renders a label set.
     *
     * @param array<string, string> $labels label name => value
     *
     * @return string `{a="1",b="2"}` or an empty string
     */
    private function formatLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $parts = [];
        foreach ($labels as $name => $value) {
            $parts[] = $name . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value) . '"';
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * Renders a sample value.
     *
     * @param float $value value
     *
     * @return string Prometheus float literal
     */
    private function formatValue(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        if (floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return var_export($value, true);
    }
}
