<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * Fails the container build when `runtime.worker_recycle.state_pool` names a service that does not exist.
 *
 * The state service tolerates a missing pool (nothing is recorded, so the metrics and the probe stay empty), which
 * would hide the typo; an explicit exception is deterministic, unlike a PHP warning that Symfony's ErrorHandler turns
 * into an ErrorException in a way that depends on the runtime. The check runs as a compiler pass because the pool is usually registered by
 * FrameworkExtension, after this bundle's extension has loaded.
 */
final class WorkerRecycleStatePoolPass implements CompilerPassInterface
{
    /**
     * Rejects an unknown state pool.
     *
     * @param ContainerBuilder $container container being compiled
     *
     * @throws InvalidConfigurationException when the configured state pool service does not exist
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('nowo_health_kit.runtime.worker_recycle_state')) {
            return;
        }

        $pool = $container->getDefinition('nowo_health_kit.runtime.worker_recycle_state')->getArgument(0);
        if (!$pool instanceof Reference) {
            return;
        }

        $id = (string) $pool;
        if (!$container->has($id)) {
            throw new InvalidConfigurationException(sprintf('"nowo_health_kit.runtime.worker_recycle.state_pool" names the service "%s", which does not exist: use an existing PSR-6 pool such as "cache.system", or set "state_pool: null" to record nothing.', $id));
        }
    }
}
