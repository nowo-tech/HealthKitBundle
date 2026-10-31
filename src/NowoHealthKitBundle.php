<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle;

use Nowo\HealthKitBundle\DependencyInjection\Compiler\DoctrineProbePass;
use Nowo\HealthKitBundle\DependencyInjection\Compiler\HealthProbePass;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

use function dirname;

/**
 * Liveness and readiness endpoints with pluggable probes (Doctrine, Redis, Messenger queue depth, FrankenPHP) and a
 * token-guarded Prometheus /metrics endpoint for Symfony apps.
 *
 * The bundle root (`@NowoHealthKitBundle`) is the package root, so route imports are
 * `@NowoHealthKitBundle/config/routes/health.yaml` and `@NowoHealthKitBundle/config/routes/metrics.yaml`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoHealthKitBundle extends Bundle
{
    /**
     * Registers the compiler passes (Doctrine probes first, then probe collection).
     *
     * @param ContainerBuilder $container container being built
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new DoctrineProbePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new HealthProbePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
    }

    /**
     * Bundle extension (`nowo_health_kit`).
     *
     * @return ExtensionInterface the extension
     */
    public function getContainerExtension(): ExtensionInterface
    {
        return new NowoHealthKitExtension();
    }

    /**
     * Package root, so `config/routes/*.yaml` resolve from `@NowoHealthKitBundle`.
     *
     * @return string absolute package root path
     */
    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
