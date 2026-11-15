<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Runtime;

use Closure;
use Symfony\Component\Form\Extension\DataCollector\FormDataCollector;
use Symfony\Contracts\Service\ResetInterface;

use function property_exists;

/**
 * Clears the per-form data of the profiler's form collector at the request boundary (`runtime.dev_collector_reset`).
 *
 * {@see FormDataCollector::reset()} clears its report but not the data it gathers while forms are built
 * (`dataByForm`, `dataByView`, `formsByView`, with every resolved option and its closures). A kept-alive FrankenPHP
 * worker keeps the collector between requests, so that data grows until the worker runs out of memory (Symfony Beacon
 * measured +340 KB per request on its login page). Runs on `kernel.terminate` at priority -4096, after the profiler's
 * late collection, and on `kernel.reset`. Meant for dev (`when@dev`); without the form collector it does nothing.
 */
final readonly class FormDataCollectorReset implements ResetInterface
{
    /** @var list<string> private collector properties cleared */
    public const PROPERTIES = ['dataByForm', 'dataByView', 'formsByView'];

    /**
     * @param object|null $collector the `data_collector.form` service (null when the profiler or symfony/form is absent)
     */
    public function __construct(
        private ?object $collector = null,
    ) {
    }

    /**
     * Clears the collector's per-form data (`kernel.terminate` listener and `kernel.reset`).
     */
    public function reset(): void
    {
        if (!$this->collector instanceof FormDataCollector) {
            return;
        }

        Closure::bind(static function (FormDataCollector $collector): void {
            foreach (FormDataCollectorReset::PROPERTIES as $property) {
                if (property_exists($collector, $property)) {
                    $collector->{$property} = [];
                }
            }
        }, null, FormDataCollector::class)($this->collector);
    }
}
