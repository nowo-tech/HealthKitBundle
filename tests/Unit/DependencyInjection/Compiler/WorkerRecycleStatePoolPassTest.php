<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HealthKitBundle\DependencyInjection\Compiler\WorkerRecycleStatePoolPass;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use const E_USER_WARNING;

#[CoversClass(WorkerRecycleStatePoolPass::class)]
final class WorkerRecycleStatePoolPassTest extends TestCase
{
    /**
     * @param array<string, mixed> $recycle
     *
     * @return list<string> warnings raised by the pass
     */
    private function warnings(array $recycle, bool $withPool = false): array
    {
        $container = new ContainerBuilder();
        (new NowoHealthKitExtension())->load([['runtime' => ['worker_recycle' => ['enabled' => true] + $recycle]]], $container);
        if ($withPool) {
            $container->register('cache.system', ArrayAdapter::class);
        }

        $warnings = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test-only, restored in finally)
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $level . ':' . $message;

            return true;
        });

        try {
            (new WorkerRecycleStatePoolPass())->process($container);
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    #[Test]
    public function aMissingPoolServiceRaisesAWarning(): void
    {
        $warnings = $this->warnings(['state_pool' => 'cache.typo']);

        self::assertCount(1, $warnings);
        self::assertStringStartsWith(E_USER_WARNING . ':', $warnings[0]);
        self::assertStringContainsString('"cache.typo"', $warnings[0]);
    }

    #[Test]
    public function anExistingOrNoPoolIsSilent(): void
    {
        self::assertSame([], $this->warnings(['state_pool' => 'cache.system'], true));
        self::assertSame([], $this->warnings([]));
    }

    #[Test]
    public function withoutTheGuardNothingHappens(): void
    {
        $container = new ContainerBuilder();
        (new NowoHealthKitExtension())->load([[]], $container);
        (new WorkerRecycleStatePoolPass())->process($container);

        self::assertFalse($container->hasDefinition('nowo_health_kit.runtime.worker_recycle_state'));
    }
}
