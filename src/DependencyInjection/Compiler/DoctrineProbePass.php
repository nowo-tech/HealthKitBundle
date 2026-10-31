<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use Nowo\HealthKitBundle\Probe\DoctrineConnectionProbe;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_diff;
use function array_keys;
use function implode;
use function is_array;
use function sprintf;

/**
 * Registers one `doctrine.<connection>` probe per DBAL connection when `probes.doctrine` is enabled.
 *
 * Exists as a compiler pass because the connection list (`%doctrine.connections%`) is only known once
 * DoctrineBundle has loaded. Unknown connection names fail the container compilation.
 */
final class DoctrineProbePass implements CompilerPassInterface
{
    /**
     * Registers the Doctrine probes.
     *
     * @param ContainerBuilder $container container being compiled
     *
     * @throws LogicException when DoctrineBundle is missing or a configured connection does not exist
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_health_kit.probes.doctrine')) {
            return;
        }
        /** @var array{enabled: bool, connections: list<string>, groups: list<string>, critical: bool, timeout: float|null} $config */
        $config = $container->getParameter('nowo_health_kit.probes.doctrine');
        if (!$config['enabled']) {
            return;
        }

        $available = $container->hasParameter('doctrine.connections') ? $container->getParameter('doctrine.connections') : null;
        if (!is_array($available) || $available === []) {
            throw new LogicException('"nowo_health_kit.probes.doctrine" is enabled but no Doctrine DBAL connection is configured (install and configure doctrine/doctrine-bundle).');
        }

        $names   = $config['connections'] === [] ? array_keys($available) : $config['connections'];
        $unknown = array_diff($names, array_keys($available));
        if ($unknown !== []) {
            throw new LogicException(sprintf('Unknown Doctrine connection(s) "%s" in "nowo_health_kit.probes.doctrine.connections"; available: "%s".', implode('", "', $unknown), implode('", "', array_keys($available))));
        }

        foreach ($names as $name) {
            $container->register('nowo_health_kit.probe.doctrine.' . $name, DoctrineConnectionProbe::class)
                ->setArguments([new Reference((string) $available[$name])])
                ->addTag(NowoHealthKitExtension::PROBE_TAG, [
                    'name'     => 'doctrine.' . $name,
                    'groups'   => $config['groups'],
                    'critical' => $config['critical'],
                    'timeout'  => $config['timeout'],
                ]);
        }
    }
}
