<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\HealthKitBundle\Attribute\AsHealthProbe;
use Nowo\HealthKitBundle\Controller\HealthController;
use Nowo\HealthKitBundle\Controller\MetricsController;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use Nowo\HealthKitBundle\EventListener\HiddenEndpointListener;
use Nowo\HealthKitBundle\Health\HealthChecker;
use Nowo\HealthKitBundle\Health\HealthCheckerInterface;
use Nowo\HealthKitBundle\Metrics\MetricsCollector;
use Nowo\HealthKitBundle\Metrics\MetricsCollectorInterface;
use Nowo\HealthKitBundle\Metrics\MetricsProviderInterface;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Tests\Fixtures\QueueProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(NowoHealthKitExtension::class)]
#[CoversClass(AsHealthProbe::class)]
final class NowoHealthKitExtensionTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoHealthKitExtension())->load([$config], $container);

        return $container;
    }

    #[Test]
    public function portsAreAliasedAndTheOldClassIdsStillResolve(): void
    {
        $container = $this->load(['metrics' => ['enabled' => true, 'token' => 'metrics-token-REDACTED-TEST']]);

        self::assertSame(NowoHealthKitExtension::CHECKER_ID, (string) $container->getAlias(HealthCheckerInterface::class));
        self::assertSame(NowoHealthKitExtension::CHECKER_ID, (string) $container->getAlias(HealthChecker::class));
        self::assertSame('nowo_health_kit.metrics_collector', (string) $container->getAlias(MetricsCollectorInterface::class));
        self::assertSame('nowo_health_kit.metrics_collector', (string) $container->getAlias(MetricsCollector::class));
        // The controllers depend on the ports, so overriding an alias reaches them.
        self::assertSame(HealthCheckerInterface::class, (string) $container->getDefinition(HealthController::class)->getArgument(0));
        self::assertSame(MetricsCollectorInterface::class, (string) $container->getDefinition(MetricsController::class)->getArgument(0));
    }

    #[Test]
    public function controllersAreNotAutoconfigured(): void
    {
        $container = $this->load();

        self::assertSame('nowo_health_kit', (new NowoHealthKitExtension())->getAlias());
        foreach ([HealthController::class, MetricsController::class] as $id) {
            $definition = $container->getDefinition($id);
            self::assertFalse($definition->isAutoconfigured(), $id);
            self::assertTrue($definition->isPublic(), $id);
            self::assertTrue($definition->hasTag('controller.service_arguments'), $id);
        }
    }

    #[Test]
    public function defaultsRegisterOnlyTheFrankenPhpProbe(): void
    {
        $container = $this->load();

        self::assertSame(['nowo_health_kit.probe.frankenphp'], array_keys($container->findTaggedServiceIds(NowoHealthKitExtension::PROBE_TAG)));
        self::assertFalse($container->hasDefinition('nowo_health_kit.metrics.probe_provider'));
        $doctrine = $container->getParameter('nowo_health_kit.probes.doctrine');
        self::assertIsArray($doctrine);
        self::assertFalse($doctrine['enabled']);
    }

    #[Test]
    public function builtInProbesAndMetricsFollowTheConfiguration(): void
    {
        $container = $this->load([
            'probes' => [
                'frankenphp' => ['enabled' => false],
                'redis'      => ['enabled' => true, 'client' => 'app.redis', 'critical' => false],
                'messenger'  => ['enabled' => true, 'transports' => ['async' => ['warn_at' => 5], 'failed' => ['fail_at' => 1]]],
            ],
            'metrics' => ['enabled' => true, 'token' => 'x', 'roles' => ['ROLE_OPS']],
        ]);

        $tags = $container->findTaggedServiceIds(NowoHealthKitExtension::PROBE_TAG);
        self::assertSame(['nowo_health_kit.probe.redis', 'nowo_health_kit.probe.messenger.async', 'nowo_health_kit.probe.messenger.failed'], array_keys($tags));
        self::assertSame(['name' => 'redis', 'groups' => ['ready'], 'critical' => false, 'timeout' => 1.0], $tags['nowo_health_kit.probe.redis'][0]);
        self::assertEquals(new Reference('app.redis'), $container->getDefinition('nowo_health_kit.probe.redis')->getArgument(0));
        self::assertEquals([new Reference('messenger.transport.failed'), null, 1], $container->getDefinition('nowo_health_kit.probe.messenger.failed')->getArguments());
        self::assertTrue($container->hasDefinition('nowo_health_kit.metrics.probe_provider'));
        self::assertSame(['ROLE_OPS'], $container->getDefinition(MetricsController::class)->getArgument(4));
    }

    #[Test]
    public function tokenProvidersAreWiredAsReferences(): void
    {
        $container = $this->load(['details' => ['token_provider' => 'app.settings_token'], 'metrics' => ['enabled' => true, 'token_provider' => 'app.settings_token']]);

        self::assertEquals(new Reference('app.settings_token'), $container->getDefinition('nowo_health_kit.details_access_policy')->getArgument(4));
        self::assertEquals(new Reference('app.settings_token'), $container->getDefinition(MetricsController::class)->getArgument(6));
        self::assertNull($this->load()->getDefinition(MetricsController::class)->getArgument(6));
        self::assertNull($this->load()->getDefinition('nowo_health_kit.details_access_policy')->getArgument(4));
    }

    #[Test]
    public function redisDsnProbeHasNoClient(): void
    {
        $container = $this->load(['probes' => ['redis' => ['enabled' => true, 'dsn' => 'redis://cache']]]);

        self::assertSame([null, 'redis://cache'], $container->getDefinition('nowo_health_kit.probe.redis')->getArguments());
    }

    #[Test]
    public function autoconfigurationTagsProbesAndProviders(): void
    {
        $container = $this->load();

        self::assertTrue($container->getAutoconfiguredInstanceof()[HealthProbeInterface::class]->hasTag(NowoHealthKitExtension::PROBE_TAG));
        self::assertTrue($container->getAutoconfiguredInstanceof()[MetricsProviderInterface::class]->hasTag(NowoHealthKitExtension::METRICS_PROVIDER_TAG));

        $configurator = $container->getAttributeAutoconfigurators()[AsHealthProbe::class][0];
        $definition   = new ChildDefinition('');
        $configurator($definition, new AsHealthProbe('payments_api', ['ready'], false, 1.5), new ReflectionClass(QueueProbe::class));
        self::assertSame([['name' => 'payments_api', 'groups' => ['ready'], 'critical' => false, 'timeout' => 1.5]], $definition->getTag(NowoHealthKitExtension::PROBE_TAG));
    }

    #[Test]
    public function publicReadinessPoolAndLoggersAreWired(): void
    {
        $default = $this->load();
        $pool    = $default->getDefinition(HealthController::class)->getArgument(6);
        self::assertInstanceOf(Reference::class, $pool);
        self::assertSame('cache.app', (string) $pool);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $pool->getInvalidBehavior());
        self::assertSame('logger', (string) $default->getDefinition('nowo_health_kit.details_access_policy')->getArgument(5));
        self::assertSame('logger', (string) $default->getDefinition(MetricsController::class)->getArgument(7));

        self::assertNull($this->load(['ready' => ['cache_pool' => null]])->getDefinition(HealthController::class)->getArgument(6));
        self::assertNull($this->load(['ready' => ['cache_pool' => '']])->getDefinition(HealthController::class)->getArgument(6));
    }

    #[Test]
    public function publicOptionsReachTheControllerAndRegisterTheListenerOnlyWhenHidden(): void
    {
        $default = $this->load();
        self::assertTrue($default->getDefinition(HealthController::class)->getArgument(7));
        self::assertTrue($default->getDefinition(HealthController::class)->getArgument(8));
        self::assertFalse($default->hasDefinition('nowo_health_kit.hidden_endpoint_listener'));

        $hidden     = $this->load(['live' => ['public' => false], 'ready' => ['public' => false]]);
        $controller = $hidden->getDefinition(HealthController::class);
        self::assertFalse($controller->getArgument(7));
        self::assertFalse($controller->getArgument(8));
        $listener = $hidden->getDefinition('nowo_health_kit.hidden_endpoint_listener');
        self::assertSame(HiddenEndpointListener::class, $listener->getClass());
        self::assertSame('nowo_health_kit.details_access_policy', (string) $listener->getArgument(0));
        $router = $listener->getArgument(1);
        self::assertInstanceOf(Reference::class, $router);
        self::assertSame('router', (string) $router);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $router->getInvalidBehavior());
        self::assertSame(['live', 'ready'], $listener->getArgument(2));
        self::assertSame([
            ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 31],
            ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 16],
        ], $listener->getTag('kernel.event_listener'));

        self::assertSame(['ready'], $this->load(['ready' => ['public' => false]])->getDefinition('nowo_health_kit.hidden_endpoint_listener')->getArgument(2));
        self::assertSame(['live'], $this->load(['live' => ['public' => false]])->getDefinition('nowo_health_kit.hidden_endpoint_listener')->getArgument(2));
    }
}
