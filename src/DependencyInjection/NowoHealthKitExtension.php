<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\DependencyInjection;

use Nowo\HealthKitBundle\Attribute\AsHealthProbe;
use Nowo\HealthKitBundle\Controller\HealthController;
use Nowo\HealthKitBundle\Controller\MetricsController;
use Nowo\HealthKitBundle\Health\ErrorSanitizer;
use Nowo\HealthKitBundle\Health\HealthChecker;
use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Nowo\HealthKitBundle\Metrics\MetricsCollector;
use Nowo\HealthKitBundle\Metrics\MetricsProviderInterface;
use Nowo\HealthKitBundle\Metrics\ProbeMetricsProvider;
use Nowo\HealthKitBundle\Metrics\PrometheusTextFormatter;
use Nowo\HealthKitBundle\Probe\FrankenPhpProbe;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Probe\MessengerTransportProbe;
use Nowo\HealthKitBundle\Probe\RedisProbe;
use Nowo\HealthKitBundle\Runtime\FrankenPhpRuntime;
use Reflector;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Loads the `nowo_health_kit` configuration and registers the bundle services.
 *
 * Controllers are registered with `autoconfigure: false` (REQ-SF-004 rule 7): their routes exist only when the host
 * imports `@NowoHealthKitBundle/config/routes/*.yaml`.
 */
final class NowoHealthKitExtension extends Extension
{
    public const PROBE_TAG            = 'nowo_health_kit.probe';
    public const METRICS_PROVIDER_TAG = 'nowo_health_kit.metrics_provider';
    public const CHECKER_ID           = 'nowo_health_kit.health_checker';

    /**
     * Configuration alias.
     *
     * @return string `nowo_health_kit`
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Loads the configuration.
     *
     * @param array<array<string, mixed>> $configs raw configuration blocks
     * @param ContainerBuilder $container container being built
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{default_timeout: float, budget: float, version: string, live: array{expose_client_ip: bool}, ready: array{public_cache_ttl: float}, details: array{public: bool, allowed_ips: list<string>, token: string|null, trust_forwarded_ip: bool}, probes: array<string, array<string, mixed>>, metrics: array{enabled: bool, token: string|null, roles: list<string>, probe_metrics: bool}} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('nowo_health_kit.default_timeout', $config['default_timeout']);
        $container->setParameter('nowo_health_kit.probes.doctrine', $config['probes']['doctrine']);

        $container->registerForAutoconfiguration(HealthProbeInterface::class)->addTag(self::PROBE_TAG);
        $container->registerAttributeForAutoconfiguration(
            AsHealthProbe::class,
            static function (ChildDefinition $definition, AsHealthProbe $attribute, Reflector $reflector): void {
                $definition->addTag(self::PROBE_TAG, [
                    'name'     => $attribute->name,
                    'groups'   => $attribute->groups,
                    'critical' => $attribute->critical,
                    'timeout'  => $attribute->timeout,
                ]);
            },
        );
        $container->registerForAutoconfiguration(MetricsProviderInterface::class)->addTag(self::METRICS_PROVIDER_TAG);

        $container->register('nowo_health_kit.error_sanitizer', ErrorSanitizer::class);
        $container->register(self::CHECKER_ID, HealthChecker::class)
            ->setArguments([
                [],
                new Reference('nowo_health_kit.clock'),
                $config['budget'],
                new Reference('nowo_health_kit.error_sanitizer'),
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('monolog.logger', ['channel' => 'health']);
        $container->setAlias(HealthChecker::class, self::CHECKER_ID);

        $container->register('nowo_health_kit.details_access_policy', DetailsAccessPolicy::class)
            ->setArguments([
                $config['details']['allowed_ips'],
                $config['details']['token'],
                $config['details']['public'],
                $config['details']['trust_forwarded_ip'],
            ]);

        $container->register('nowo_health_kit.frankenphp_runtime', FrankenPhpRuntime::class)
            ->setArguments([new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $container->setAlias(FrankenPhpRuntime::class, 'nowo_health_kit.frankenphp_runtime');

        $this->registerProbes($config['probes'], $container);
        $this->registerControllers($config, $container);
    }

    /**
     * Registers the built-in Redis, Messenger and FrankenPHP probes (Doctrine probes come from a compiler pass).
     *
     * @param array<string, array<string, mixed>> $probes `probes` configuration
     * @param ContainerBuilder $container container being built
     */
    private function registerProbes(array $probes, ContainerBuilder $container): void
    {
        if ($probes['frankenphp']['enabled']) {
            $container->register('nowo_health_kit.probe.frankenphp', FrankenPhpProbe::class)
                ->setArguments([new Reference('nowo_health_kit.frankenphp_runtime'), $probes['frankenphp']['require_worker']])
                ->addTag(self::PROBE_TAG, $this->tagAttributes('frankenphp', $probes['frankenphp']));
        }

        if ($probes['redis']['enabled']) {
            $client = $probes['redis']['client'];
            $container->register('nowo_health_kit.probe.redis', RedisProbe::class)
                ->setArguments([
                    $client === null || $client === '' ? null : new Reference((string) $client),
                    $probes['redis']['dsn'],
                ])
                ->addTag(self::PROBE_TAG, $this->tagAttributes('redis', $probes['redis']));
        }

        if ($probes['messenger']['enabled']) {
            /** @var array<string, array{warn_at: int|null, fail_at: int|null}> $transports */
            $transports = $probes['messenger']['transports'];
            foreach ($transports as $name => $thresholds) {
                $container->register('nowo_health_kit.probe.messenger.' . $name, MessengerTransportProbe::class)
                    ->setArguments([new Reference('messenger.transport.' . $name), $thresholds['warn_at'], $thresholds['fail_at']])
                    ->addTag(self::PROBE_TAG, $this->tagAttributes('messenger.' . $name, $probes['messenger']));
            }
        }
    }

    /**
     * Registers the controllers (not autoconfigured), the metrics services and the probe metrics provider.
     *
     * @param array<string, mixed> $config processed configuration
     * @param ContainerBuilder $container container being built
     */
    private function registerControllers(array $config, ContainerBuilder $container): void
    {
        /** @var array{expose_client_ip: bool} $live */
        $live = $config['live'];
        /** @var array{public_cache_ttl: float} $ready */
        $ready = $config['ready'];
        /** @var array{enabled: bool, token: string|null, roles: list<string>, probe_metrics: bool} $metrics */
        $metrics = $config['metrics'];

        $container->setDefinition(HealthController::class, (new Definition(HealthController::class))
            ->setArguments([
                new Reference(self::CHECKER_ID),
                new Reference('nowo_health_kit.details_access_policy'),
                new Reference('nowo_health_kit.clock'),
                (string) $config['version'],
                $live['expose_client_ip'],
                $ready['public_cache_ttl'],
            ])
            ->setPublic(true)
            ->setAutoconfigured(false)
            ->addTag('controller.service_arguments'));

        $container->register('nowo_health_kit.prometheus_formatter', PrometheusTextFormatter::class);
        $container->setAlias(PrometheusTextFormatter::class, 'nowo_health_kit.prometheus_formatter');
        $container->register('nowo_health_kit.metrics_collector', MetricsCollector::class)
            ->setArguments([
                new TaggedIteratorArgument(self::METRICS_PROVIDER_TAG),
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->addTag('monolog.logger', ['channel' => 'health']);
        $container->setAlias(MetricsCollector::class, 'nowo_health_kit.metrics_collector');

        if ($metrics['enabled'] && $metrics['probe_metrics']) {
            $container->register('nowo_health_kit.metrics.probe_provider', ProbeMetricsProvider::class)
                ->setArguments([new Reference(self::CHECKER_ID)])
                ->addTag(self::METRICS_PROVIDER_TAG, ['priority' => -100]);
        }

        $container->setDefinition(MetricsController::class, (new Definition(MetricsController::class))
            ->setArguments([
                new Reference('nowo_health_kit.metrics_collector'),
                new Reference('nowo_health_kit.prometheus_formatter'),
                $metrics['enabled'],
                $metrics['token'],
                $metrics['roles'],
                new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->setPublic(true)
            ->setAutoconfigured(false)
            ->addTag('controller.service_arguments'));
    }

    /**
     * Probe tag attributes from a built-in probe configuration.
     *
     * @param string $name probe name
     * @param array<string, mixed> $config probe configuration (groups, critical, timeout)
     *
     * @return array{name: string, groups: mixed, critical: mixed, timeout: mixed} tag attributes
     */
    private function tagAttributes(string $name, array $config): array
    {
        return [
            'name'     => $name,
            'groups'   => $config['groups'],
            'critical' => $config['critical'],
            'timeout'  => $config['timeout'],
        ];
    }
}
