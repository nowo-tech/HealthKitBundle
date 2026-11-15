<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Runtime;

use Nowo\HealthKitBundle\Runtime\FormDataCollectorReset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\DataCollector\FormDataCollector;
use Symfony\Component\Form\Extension\DataCollector\FormDataExtractor;
use Symfony\Component\Form\Forms;

use function gc_collect_cycles;
use function memory_get_usage;

#[CoversClass(FormDataCollectorReset::class)]
final class FormDataCollectorResetTest extends TestCase
{
    #[Test]
    public function clearsThePerFormDataTheCollectorKeepsAcrossRequests(): void
    {
        $collector = new FormDataCollector(new FormDataExtractor());
        $reset     = new FormDataCollectorReset($collector);
        $factory   = Forms::createFormFactory();

        $baseline = 0;
        for ($i = 0; $i < 300; ++$i) {
            $form = $factory->createBuilder(FormType::class)->add('name', TextType::class)->getForm();
            $view = $form->createView();
            $collector->collectConfiguration($form);
            $collector->associateFormWithView($form, $view);
            $collector->buildPreliminaryFormTree($form);
            $collector->collectViewVariables($view);
            $collector->buildFinalFormTree($form, $view);
            foreach (FormDataCollectorReset::PROPERTIES as $property) {
                self::assertNotSame([], (new ReflectionProperty(FormDataCollector::class, $property))->getValue($collector), $property . ' is filled');
            }

            // The profiler resets the report itself; the per-form data is what only this listener clears.
            $collector->reset();
            $reset->reset();
            foreach (FormDataCollectorReset::PROPERTIES as $property) {
                self::assertSame([], (new ReflectionProperty(FormDataCollector::class, $property))->getValue($collector), $property . ' is cleared');
            }
            unset($form, $view);
            if ($i === 20) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
    }

    #[Test]
    public function withoutTheCollectorNothingHappens(): void
    {
        (new FormDataCollectorReset())->reset();
        $other = new stdClass();
        (new FormDataCollectorReset($other))->reset();
        self::assertSame([], (array) $other);
    }
}
