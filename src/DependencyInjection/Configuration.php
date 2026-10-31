<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_diff;
use function is_array;
use function is_string;
use function preg_match;

use const FILTER_VALIDATE_IP;

/**
 * `nowo_health_kit` configuration tree.
 *
 * Safe defaults: details are shown to loopback callers only, no token is configured, `/metrics` is disabled and,
 * when enabled, answers 503 until a token (≥ 16 chars) or a role is set. Only the FrankenPHP runtime probe is on by
 * default; database, Redis and Messenger probes are opt-in.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_health_kit';

    /** @var list<string> */
    public const GROUPS = ['live', 'ready'];

    /**
     * Builds the configuration tree.
     *
     * @return TreeBuilder the `nowo_health_kit` tree
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $children = $root->children();
        $children
            ->floatNode('default_timeout')
                ->info('Seconds a probe may spend when it has no own timeout. A probe that overruns is reported as an error.')
                ->defaultValue(2.0)
                ->min(0.001)
            ->end()
            ->floatNode('budget')
                ->info('Seconds a whole group (live or ready) may spend; probes not started in time are reported as errors.')
                ->defaultValue(5.0)
                ->min(0.001)
            ->end()
            ->scalarNode('version')
                ->info('Application version shown by /health/live (e.g. "%env(default::APP_VERSION)%"). Empty: hidden.')
                ->defaultValue('')
            ->end()
            ->arrayNode('live')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('expose_client_ip')
                        ->info('Include the caller IP as resolved through trusted proxies in /health/live (useful to verify proxy setup).')
                        ->defaultFalse()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('ready')
                ->addDefaultsIfNotSet()
                ->children()
                    ->floatNode('public_cache_ttl')
                        ->info('Seconds a public (status-only) readiness answer is reused (per-worker memo + cache_pool). 0 disables both.')
                        ->defaultValue(5.0)
                        ->min(0.0)
                    ->end()
                    ->scalarNode('cache_pool')
                        ->info('PSR-6 pool sharing the public readiness answer across PHP processes (FPM / classic mode). Null: per-worker memo only. A missing service is ignored.')
                        ->defaultValue('cache.app')
                    ->end()
                ->end()
            ->end();

        $this->addDetailsNode($children);
        $this->addProbesNode($children);
        $this->addMetricsNode($children);

        return $treeBuilder;
    }

    /**
     * `details` node: who may see probe names, durations and errors.
     *
     * @param NodeBuilder<ArrayNodeDefinition> $children root children builder
     */
    private function addDetailsNode(NodeBuilder $children): void
    {
        $children
            ->arrayNode('details')
                ->info('Who may see probe names, durations, messages and data. Everybody else gets the status only.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('public')
                        ->info('Show details to every caller. Only for dev/test (when@dev / when@test).')
                        ->defaultFalse()
                    ->end()
                    ->arrayNode('allowed_ips')
                        ->info('IPs or CIDRs (matched against REMOTE_ADDR) allowed to see details. Default: loopback only.')
                        ->scalarPrototype()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || !self::isIpOrCidr($v))
                                ->thenInvalid('Invalid value %s in "nowo_health_kit.details.allowed_ips": expected an IPv4/IPv6 address or CIDR (e.g. "10.0.0.0/8", "::1").')
                            ->end()
                        ->end()
                        ->defaultValue(['127.0.0.1', '::1'])
                    ->end()
                    ->scalarNode('token')
                        ->info('Bearer token granting details (Authorization: Bearer <token>; at least 16 characters, else ignored). Use an env var.')
                        ->defaultNull()
                        ->beforeNormalization()
                            ->ifString()->then(static fn (string $v): ?string => $v === '' ? null : $v)
                        ->end()
                    ->end()
                    ->scalarNode('token_provider')
                        ->info('Service id of a DetailsTokenProviderInterface resolving the token per request (runtime-editable tokens). Mutually exclusive with token.')
                        ->defaultNull()
                    ->end()
                    ->booleanNode('trust_forwarded_ip')
                        ->info('Match allowed_ips against Request::getClientIp() (trusted proxies) instead of REMOTE_ADDR.')
                        ->defaultFalse()
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $v): bool => self::bothTokenSources($v))
                    ->thenInvalid('"nowo_health_kit.details.token" and "nowo_health_kit.details.token_provider" are mutually exclusive: set only one.')
                ->end()
            ->end();
    }

    /**
     * `probes` node: built-in probes.
     *
     * @param NodeBuilder<ArrayNodeDefinition> $children root children builder
     */
    private function addProbesNode(NodeBuilder $children): void
    {
        $probes = $children
            ->arrayNode('probes')
                ->info('Built-in probes. Custom probes: implement HealthProbeInterface (+ #[AsHealthProbe]).')
                ->addDefaultsIfNotSet()
                ->children();

        $doctrine = $probes->arrayNode('doctrine')->info('One "doctrine.<connection>" probe per DBAL connection (SELECT 1). Needs doctrine/doctrine-bundle.')->canBeEnabled()->children();
        $doctrine
            ->arrayNode('connections')
                ->info('Connection names to ping. Empty: every configured DBAL connection.')
                ->scalarPrototype()->cannotBeEmpty()->end()
            ->end();
        $this->addProbeSettings($doctrine, ['ready'], true, null);

        $redis = $probes->arrayNode('redis')->info('"redis" probe (PING). Set either dsn or client.')->canBeEnabled()->children();
        $redis
            ->scalarNode('dsn')->info('Redis DSN, e.g. "%env(REDIS_URL)%". A short-lived connection is opened per check.')->defaultNull()->end()
            ->scalarNode('client')->info('Service id of an existing Redis client (\Redis, \Relay\Relay, Predis) to ping instead of a DSN.')->defaultNull()->end();
        $this->addProbeSettings($redis, ['ready'], true, 1.0);
        $redis->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['enabled'] && ($v['dsn'] === null || $v['dsn'] === '') === ($v['client'] === null || $v['client'] === ''))
                ->thenInvalid('"nowo_health_kit.probes.redis" needs exactly one of "dsn" or "client" when enabled.')
            ->end();

        $messenger = $probes->arrayNode('messenger')->info('One "messenger.<transport>" probe per transport (queue depth vs thresholds). Needs symfony/messenger.')->canBeEnabled()->children();
        $messenger
            ->arrayNode('transports')
                ->info('Transport name => thresholds. A transport that cannot count its messages reports degraded.')
                ->useAttributeAsKey('name')
                ->arrayPrototype()
                    ->children()
                        ->integerNode('warn_at')->info('Message count from which the probe is degraded (null: never).')->defaultNull()->validate()->ifTrue(static fn (mixed $v): bool => $v !== null && $v < 1)->thenInvalid('Invalid threshold %s for "warn_at": expected an integer >= 1, or null.')->end()->end()
                        ->integerNode('fail_at')->info('Message count from which the probe fails (null: never).')->defaultNull()->validate()->ifTrue(static fn (mixed $v): bool => $v !== null && $v < 1)->thenInvalid('Invalid threshold %s for "fail_at": expected an integer >= 1, or null.')->end()->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['warn_at'] !== null && $v['fail_at'] !== null && $v['warn_at'] > $v['fail_at'])
                        ->thenInvalid('Messenger thresholds %s: "warn_at" must be lower than or equal to "fail_at".')
                    ->end()
                ->end()
            ->end();
        $this->addProbeSettings($messenger, ['ready'], false, null);
        $messenger->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['enabled'] && $v['transports'] === [])
                ->thenInvalid('"nowo_health_kit.probes.messenger.transports" must list at least one transport when the probe is enabled.')
            ->end();

        $frankenphp = $probes->arrayNode('frankenphp')->info('"frankenphp" probe: runtime mode (classic/worker), worker number, kernel reset.')->canBeDisabled()->children();
        $frankenphp
            ->booleanNode('require_worker')->info('Fail when the request is not served by a FrankenPHP worker.')->defaultFalse()->end();
        $this->addProbeSettings($frankenphp, ['live'], true, null);
    }

    /**
     * Shared probe settings: groups, critical, timeout.
     *
     * @param NodeBuilder<ArrayNodeDefinition> $node probe children builder
     * @param list<string> $defaultGroups default groups
     * @param bool $defaultCritical default criticality
     * @param float|null $defaultTimeout default timeout (null: default_timeout)
     */
    private function addProbeSettings(NodeBuilder $node, array $defaultGroups, bool $defaultCritical, ?float $defaultTimeout): void
    {
        $node
            ->arrayNode('groups')
                ->info('Endpoints running the probe: "live" and/or "ready".')
                ->scalarPrototype()->end()
                ->defaultValue($defaultGroups)
                ->requiresAtLeastOneElement()
                ->validate()
                    ->ifTrue(static fn (array $v): bool => array_diff($v, self::GROUPS) !== [])
                    ->thenInvalid('Invalid probe groups %s: allowed values are "live" and "ready".')
                ->end()
            ->end()
            ->booleanNode('critical')
                ->info('When false a failure only degrades the report (HTTP 200) instead of failing it (HTTP 503).')
                ->defaultValue($defaultCritical)
            ->end()
            ->floatNode('timeout')
                ->info('Seconds the probe may spend (null: default_timeout).')
                ->defaultValue($defaultTimeout)
                ->validate()
                    ->ifTrue(static fn (mixed $v): bool => $v !== null && $v <= 0)
                    ->thenInvalid('Invalid probe timeout %s: expected a number of seconds greater than 0, or null.')
                ->end()
            ->end();
    }

    /**
     * `metrics` node: token-guarded Prometheus endpoint.
     *
     * @param NodeBuilder<ArrayNodeDefinition> $children root children builder
     */
    private function addMetricsNode(NodeBuilder $children): void
    {
        $children
            ->arrayNode('metrics')
                ->info('Token-guarded Prometheus /metrics endpoint aggregating MetricsProviderInterface services.')
                ->canBeEnabled()
                ->children()
                    ->scalarNode('token')
                        ->info('Bearer token for scrapers (at least 16 characters, else ignored). Use an env var. ?token= is never accepted.')
                        ->defaultNull()
                        ->beforeNormalization()
                            ->ifString()->then(static fn (string $v): ?string => $v === '' ? null : $v)
                        ->end()
                    ->end()
                    ->scalarNode('token_provider')
                        ->info('Service id of a MetricsTokenProviderInterface resolving the token on every scrape (runtime-editable tokens). Mutually exclusive with token.')
                        ->defaultNull()
                    ->end()
                    ->arrayNode('roles')
                        ->info('Session roles also allowed to scrape (e.g. ROLE_ADMIN). Empty: no role grants access.')
                        ->scalarPrototype()->cannotBeEmpty()->end()
                        ->defaultValue([])
                    ->end()
                    ->booleanNode('probe_metrics')
                        ->info('Export every health probe as health_probe_status / health_probe_duration_seconds / health_probe_data gauges.')
                        ->defaultTrue()
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $v): bool => self::bothTokenSources($v))
                    ->thenInvalid('"nowo_health_kit.metrics.token" and "nowo_health_kit.metrics.token_provider" are mutually exclusive: set only one.')
                ->end()
            ->end();
    }

    /**
     * Whether a node sets both a static token and a token provider.
     *
     * @param array<string, mixed> $node processed `details` or `metrics` node
     *
     * @return bool true when both are set
     */
    private static function bothTokenSources(array $node): bool
    {
        return ($node['token'] ?? null) !== null && ($node['token_provider'] ?? null) !== null && $node['token_provider'] !== '';
    }

    /**
     * Whether the value is an IPv4/IPv6 address or CIDR.
     *
     * @param string $value value to check
     *
     * @return bool true when valid
     */
    public static function isIpOrCidr(string $value): bool
    {
        $parts = explode('/', $value, 2);
        $ip    = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (!isset($parts[1])) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return preg_match('/^\d{1,3}$/', $parts[1]) === 1 && (int) $parts[1] <= $max;
    }

    /**
     * Normalizes a list of groups (kept for the compiler pass).
     *
     * @param mixed $groups raw value from a service tag
     *
     * @return list<string>|null the groups, or null when the value is not a non-empty list of valid groups
     */
    public static function normalizeGroups(mixed $groups): ?array
    {
        if (is_string($groups)) {
            $groups = [$groups];
        }
        if (!is_array($groups) || $groups === []) {
            return null;
        }
        $out = [];
        foreach ($groups as $group) {
            if (!is_string($group) || array_diff([$group], self::GROUPS) !== []) {
                return null;
            }
            $out[] = $group;
        }

        return $out;
    }
}
