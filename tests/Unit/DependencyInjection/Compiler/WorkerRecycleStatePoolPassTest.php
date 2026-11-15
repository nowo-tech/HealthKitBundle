<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HealthKitBundle\DependencyInjection\Compiler\WorkerRecycleStatePoolPass;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(WorkerRecycleStatePoolPass::class)]
final class WorkerRecycleStatePoolPassTest extends TestCase
{
    /**
     * @param array<string, mixed> $recycle
     */
    private function process(array $recycle, bool $withPool = false): void
    {
        $container = new ContainerBuilder();
        (new NowoHealthKitExtension())->load([['runtime' => ['worker_recycle' => ['enabled' => true] + $recycle]]], $container);
        if ($withPool) {
            $container->register('cache.system', ArrayAdapter::class);
        }

        (new WorkerRecycleStatePoolPass())->process($container);
    }

    #[Test]
    public function aMissingPoolServiceFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('names the service "cache.typo", which does not exist');
        $this->process(['state_pool' => 'cache.typo']);
    }

    #[Test]
    public function anExistingOrNoPoolPasses(): void
    {
        $this->process(['state_pool' => 'cache.system'], true);
        $this->process([]);
        $this->expectNotToPerformAssertions();
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
