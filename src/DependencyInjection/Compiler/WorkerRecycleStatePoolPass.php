<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/**
 * Warns when `runtime.worker_recycle.state_pool` names a service that does not exist.
 *
 * The state service tolerates a missing pool (nothing is recorded, so the metrics and the probe stay empty), which
 * would otherwise hide the typo. The check runs as a compiler pass because the pool is usually registered by
 * FrameworkExtension, after this bundle's extension has loaded.
 */
final class WorkerRecycleStatePoolPass implements CompilerPassInterface
{
    /**
     * Raises a warning for an unknown state pool.
     *
     * @param ContainerBuilder $container container being compiled
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
            trigger_error(sprintf('"nowo_health_kit.runtime.worker_recycle.state_pool" names the service "%s", which does not exist: worker recycles will not be recorded (no metrics, empty probe).', $id), E_USER_WARNING);
        }
    }
}
