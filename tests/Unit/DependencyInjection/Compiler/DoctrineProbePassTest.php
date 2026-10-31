<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\HealthKitBundle\DependencyInjection\Compiler\DoctrineProbePass;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[CoversClass(DoctrineProbePass::class)]
final class DoctrineProbePassTest extends TestCase
{
    /**
     * @param array<string, mixed> $doctrine
     * @param array<string, string>|null $connections
     */
    private function container(array $doctrine, ?array $connections = ['default' => 'doctrine.dbal.default_connection', 'reporting' => 'doctrine.dbal.reporting_connection']): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoHealthKitExtension())->load([['probes' => ['doctrine' => $doctrine]]], $container);
        if ($connections !== null) {
            $container->setParameter('doctrine.connections', $connections);
        }

        return $container;
    }

    #[Test]
    public function registersOneProbePerConnection(): void
    {
        $container = $this->container(['enabled' => true, 'critical' => false, 'groups' => ['ready', 'live']]);
        (new DoctrineProbePass())->process($container);

        self::assertEquals([new Reference('doctrine.dbal.reporting_connection')], $container->getDefinition('nowo_health_kit.probe.doctrine.reporting')->getArguments());
        self::assertSame(
            [['name' => 'doctrine.default', 'groups' => ['ready', 'live'], 'critical' => false, 'timeout' => null]],
            $container->getDefinition('nowo_health_kit.probe.doctrine.default')->getTag(NowoHealthKitExtension::PROBE_TAG),
        );
    }

    #[Test]
    public function filtersConfiguredConnections(): void
    {
        $container = $this->container(['enabled' => true, 'connections' => ['reporting']]);
        (new DoctrineProbePass())->process($container);

        self::assertFalse($container->hasDefinition('nowo_health_kit.probe.doctrine.default'));
        self::assertTrue($container->hasDefinition('nowo_health_kit.probe.doctrine.reporting'));
    }

    #[Test]
    public function disabledOrUnconfiguredDoesNothing(): void
    {
        $disabled = $this->container(['enabled' => false]);
        (new DoctrineProbePass())->process($disabled);
        self::assertFalse($disabled->hasDefinition('nowo_health_kit.probe.doctrine.default'));

        $bare = new ContainerBuilder();
        (new DoctrineProbePass())->process($bare);
        self::assertSame([], $bare->getDefinitions() === [] ? [] : array_keys($bare->findTaggedServiceIds(NowoHealthKitExtension::PROBE_TAG)));
    }

    #[Test]
    public function failsWithoutConnections(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Doctrine DBAL connection is configured');
        (new DoctrineProbePass())->process($this->container(['enabled' => true], null));
    }

    #[Test]
    public function failsOnUnknownConnection(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown Doctrine connection(s) "audit" in "nowo_health_kit.probes.doctrine.connections"; available: "default", "reporting".');
        (new DoctrineProbePass())->process($this->container(['enabled' => true, 'connections' => ['audit']]));
    }
}
