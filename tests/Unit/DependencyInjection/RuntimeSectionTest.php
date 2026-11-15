<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\HealthKitBundle\DependencyInjection\Configuration;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use Nowo\HealthKitBundle\Metrics\WorkerRecycleMetricsProvider;
use Nowo\HealthKitBundle\Probe\WorkerRecycleProbe;
use Nowo\HealthKitBundle\Runtime\FormDataCollectorReset;
use Nowo\HealthKitBundle\Runtime\WorkerRecycleListener;
use Nowo\HealthKitBundle\Runtime\WorkerRecyclerInterface;
use Nowo\HealthKitBundle\Runtime\WorkerRecycleState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * `runtime` section: opt-in configuration and wiring.
 */
#[CoversClass(Configuration::class)]
#[CoversClass(NowoHealthKitExtension::class)]
final class RuntimeSectionTest extends TestCase
{
    /**
     * @param array<string, mixed> $runtime
     *
     * @return array<string, mixed>
     */
    private function process(array $runtime): array
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['runtime' => $runtime]]);
        self::assertIsArray($config['runtime']);

        return $config['runtime'];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoHealthKitExtension())->load([$config], $container);

        return $container;
    }

    #[Test]
    public function everythingIsOffByDefault(): void
    {
        self::assertSame([
            'dev_collector_reset' => false,
            'worker_recycle'      => [
                'enabled'                       => false,
                'redis'                         => true,
                'exception_classes'             => [],
                'allow_broad_exception_classes' => false,
                'log_level'                     => 'critical',
                'state_pool'                    => null,
                'recent_window'                 => 300,
                'probe'                         => ['enabled' => false, 'groups' => ['ready'], 'critical' => false, 'timeout' => null],
            ],
        ], $this->process([]));

        $container = $this->load([]);
        foreach (['nowo_health_kit.runtime.form_collector_reset', NowoHealthKitExtension::WORKER_RECYCLER_ID, 'nowo_health_kit.runtime.worker_recycle_state', 'nowo_health_kit.metrics.worker_recycle_provider', 'nowo_health_kit.probe.worker_recycle'] as $id) {
            self::assertFalse($container->hasDefinition($id), $id);
        }
        self::assertFalse($container->hasAlias(WorkerRecyclerInterface::class));
    }

    #[Test]
    public function devCollectorResetIsWiredOnTerminateAndReset(): void
    {
        $definition = $this->load(['runtime' => ['dev_collector_reset' => true]])->getDefinition('nowo_health_kit.runtime.form_collector_reset');

        self::assertSame(FormDataCollectorReset::class, $definition->getClass());
        $argument = $definition->getArgument(0);
        self::assertInstanceOf(Reference::class, $argument);
        self::assertSame('data_collector.form', (string) $argument);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $argument->getInvalidBehavior());
        self::assertSame([['event' => 'kernel.terminate', 'method' => 'reset', 'priority' => -4096]], $definition->getTag('kernel.event_listener'));
        self::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
    }

    #[Test]
    public function workerRecycleIsWiredWithTriggersStateMetricsAndProbe(): void
    {
        $container = $this->load([
            'metrics' => ['enabled' => true, 'token' => 'metrics-token-REDACTED-TEST'],
            'runtime' => ['worker_recycle' => [
                'enabled'           => true,
                'exception_classes' => ['\\Doctrine\\DBAL\\Exception\\ConnectionLost', 'RedisException'],
                'log_level'         => 'error',
                'state_pool'        => 'cache.system',
                'recent_window'     => 60,
                'probe'             => ['enabled' => true],
            ]],
        ]);

        $listener = $container->getDefinition(NowoHealthKitExtension::WORKER_RECYCLER_ID);
        self::assertSame(WorkerRecycleListener::class, $listener->getClass());
        self::assertSame([...NowoHealthKitExtension::REDIS_EXCEPTION_CLASSES, 'Doctrine\\DBAL\\Exception\\ConnectionLost'], $listener->getArgument(0));
        self::assertSame('error', $listener->getArgument(4));
        self::assertSame([
            ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 4096],
            ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 2048],
            ['event' => 'kernel.terminate', 'method' => 'onKernelTerminate', 'priority' => -8192],
        ], $listener->getTag('kernel.event_listener'));
        self::assertSame([['method' => 'reset']], $listener->getTag('kernel.reset'));
        self::assertSame(NowoHealthKitExtension::WORKER_RECYCLER_ID, (string) $container->getAlias(WorkerRecyclerInterface::class));

        $state = $container->getDefinition('nowo_health_kit.runtime.worker_recycle_state');
        self::assertSame(WorkerRecycleState::class, $state->getClass());
        self::assertSame('cache.system', (string) $state->getArgument(0));
        self::assertSame('nowo_health_kit.runtime.worker_recycle_state', (string) $container->getAlias(WorkerRecycleState::class));

        self::assertSame(WorkerRecycleMetricsProvider::class, $container->getDefinition('nowo_health_kit.metrics.worker_recycle_provider')->getClass());
        $probe = $container->getDefinition('nowo_health_kit.probe.worker_recycle');
        self::assertSame(WorkerRecycleProbe::class, $probe->getClass());
        self::assertSame(60, $probe->getArgument(2));
        self::assertSame([['name' => 'runtime.worker_recycle', 'groups' => ['ready'], 'critical' => false, 'timeout' => null]], $probe->getTag(NowoHealthKitExtension::PROBE_TAG));
    }

    #[Test]
    public function workerRecycleWithoutPoolMetricsOrProbe(): void
    {
        $container = $this->load(['runtime' => ['worker_recycle' => ['enabled' => true, 'redis' => false, 'exception_classes' => ['App\\Infra\\LostConnection'], 'state_pool' => '']]]);

        self::assertSame(['App\\Infra\\LostConnection'], $container->getDefinition(NowoHealthKitExtension::WORKER_RECYCLER_ID)->getArgument(0));
        self::assertNull($container->getDefinition('nowo_health_kit.runtime.worker_recycle_state')->getArgument(0));
        self::assertFalse($container->hasDefinition('nowo_health_kit.metrics.worker_recycle_provider'));
        self::assertFalse($container->hasDefinition('nowo_health_kit.probe.worker_recycle'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRuntime(): iterable
    {
        yield 'no trigger' => [['worker_recycle' => ['enabled' => true, 'redis' => false]], 'needs "redis: true" or at least one'];
        yield 'probe without pool' => [['worker_recycle' => ['enabled' => true, 'probe' => ['enabled' => true]]], 'needs "state_pool"'];
        yield 'broad Exception' => [['worker_recycle' => ['exception_classes' => ['\\Exception']]], 'broad class(es) \\Exception'];
        yield 'broad RuntimeException' => [['worker_recycle' => ['exception_classes' => ['App\\Ok', 'runtimeexception']]], 'allow_broad_exception_classes'];
        yield 'broad http interface' => [['worker_recycle' => ['exception_classes' => ['Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface']]], 'broad class'];
        yield 'broad DBAL driver' => [['worker_recycle' => ['exception_classes' => ['Doctrine\\DBAL\\Exception\\DriverException']]], 'broad class'];
        yield 'http subclass' => [['worker_recycle' => ['exception_classes' => ['Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException']]], 'broad class'];
        yield 'dbal constraint subclass' => [['worker_recycle' => ['exception_classes' => ['Doctrine\\DBAL\\Exception\\UniqueConstraintViolationException']]], 'broad class'];
        yield 'type error' => [['worker_recycle' => ['exception_classes' => ['TypeError']]], 'broad class'];
        yield 'bad class name' => [['worker_recycle' => ['exception_classes' => ['not a class']]], 'expected a fully qualified class or interface name'];
        yield 'non-string class' => [['worker_recycle' => ['exception_classes' => [12]]], 'expected a fully qualified class or interface name'];
        yield 'bad level' => [['worker_recycle' => ['log_level' => 'loud']], 'log_level'];
        yield 'negative window' => [['worker_recycle' => ['recent_window' => -1]], 'recent_window'];
        yield 'bad probe group' => [['worker_recycle' => ['probe' => ['groups' => ['other']]]], 'allowed values are "live" and "ready"'];
    }

    /**
     * @param array<string, mixed> $runtime
     */
    #[Test]
    #[DataProvider('invalidRuntime')]
    public function invalidRuntimeConfigurationIsRejected(array $runtime, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($runtime);
    }

    #[Test]
    public function disabledGuardSkipsTheTriggerRules(): void
    {
        self::assertFalse($this->process(['worker_recycle' => ['enabled' => false, 'redis' => false, 'probe' => ['enabled' => true]]])['worker_recycle']['enabled']);
    }

    #[Test]
    public function broadClassesAreAcceptedWithTheExplicitOptOut(): void
    {
        $recycle = $this->process(['worker_recycle' => ['enabled' => true, 'exception_classes' => ['RuntimeException'], 'allow_broad_exception_classes' => true]])['worker_recycle'];

        self::assertSame(['RuntimeException'], $recycle['exception_classes']);
        self::assertSame(['Exception'], $this->process(['worker_recycle' => ['enabled' => false, 'exception_classes' => ['Exception']]])['worker_recycle']['exception_classes']);
    }

    #[Test]
    public function specificAndMissingClassesAreAccepted(): void
    {
        $classes = ['Doctrine\\DBAL\\Exception\\ConnectionLost', 'Not\\Installed\\Thing', 'App\\Ok'];

        self::assertSame($classes, $this->process(['worker_recycle' => ['enabled' => true, 'exception_classes' => $classes]])['worker_recycle']['exception_classes']);
    }
}
