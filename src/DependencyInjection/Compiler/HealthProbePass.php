<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\DependencyInjection\Compiler;

use InvalidArgumentException;
use Nowo\HealthKitBundle\DependencyInjection\Configuration;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use Nowo\HealthKitBundle\Health\RegisteredProbe;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_values;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strrchr;
use function strtolower;
use function substr;

/**
 * Collects the `nowo_health_kit.probe` services into the health checker and wires the clock.
 *
 * - A service may carry several probe tags (autoconfiguration by interface + `#[AsHealthProbe]`); the tag holding a
 *   `name` wins, otherwise the name is the snake_case short class name.
 * - Names must match `[a-z0-9_.-]+` and be unique; probes run and are reported in name order; groups must be `live` and/or `ready`; timeouts must be > 0.
 * - The checker uses the framework `clock` service when present, else a {@see NativeClock}.
 */
final class HealthProbePass implements CompilerPassInterface
{
    /**
     * Builds the registered probe list.
     *
     * @param ContainerBuilder $container container being compiled
     *
     * @throws InvalidArgumentException on an invalid or duplicated probe definition
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoHealthKitExtension::CHECKER_ID)) {
            return;
        }

        if ($container->has('clock')) {
            $container->setAlias('nowo_health_kit.clock', 'clock');
        } else {
            $container->register('nowo_health_kit.clock', NativeClock::class);
        }

        $defaultTimeout = (float) $container->getParameter('nowo_health_kit.default_timeout');
        $registered     = [];
        $seen           = [];

        foreach ($container->findTaggedServiceIds(NowoHealthKitExtension::PROBE_TAG) as $id => $tags) {
            $attributes = $tags[0];
            foreach ($tags as $tag) {
                if (isset($tag['name'])) {
                    $attributes = $tag;

                    break;
                }
            }

            $name = $attributes['name'] ?? $this->defaultName((string) $container->getDefinition($id)->getClass());
            if (!is_string($name) || preg_match('/^[a-z0-9_.\-]+$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf('Health probe service "%s": invalid name; expected lowercase letters, digits, "_", "." or "-".', $id));
            }
            if (isset($seen[$name])) {
                throw new InvalidArgumentException(sprintf('Health probe name "%s" is used by both "%s" and "%s".', $name, $seen[$name], $id));
            }
            $seen[$name] = $id;

            $groups = Configuration::normalizeGroups($attributes['groups'] ?? ['ready']);
            if ($groups === null) {
                throw new InvalidArgumentException(sprintf('Health probe "%s": groups must be a non-empty list of "live" and/or "ready".', $name));
            }

            $timeout = $attributes['timeout'] ?? $defaultTimeout;
            if ((!is_float($timeout) && !is_int($timeout)) || $timeout <= 0) {
                throw new InvalidArgumentException(sprintf('Health probe "%s": timeout must be a number of seconds greater than 0.', $name));
            }

            $critical = $attributes['critical'] ?? true;

            $registered[$name] = new Definition(RegisteredProbe::class, [
                $name,
                new Reference($id),
                $groups,
                is_bool($critical) ? $critical : (bool) $critical,
                (float) $timeout,
            ]);
        }

        ksort($registered);
        $container->getDefinition(NowoHealthKitExtension::CHECKER_ID)->replaceArgument(0, array_values($registered));
    }

    /**
     * Default probe name: snake_case short class name without a `Probe` suffix.
     *
     * @param string $class probe class
     *
     * @return string probe name
     */
    private function defaultName(string $class): string
    {
        $short = strrchr($class, '\\');
        $short = $short === false ? $class : substr($short, 1);
        $short = (string) preg_replace('/(?<!^)Probe$/', '', $short);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }
}
