<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\HealthKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    #[Test]
    public function safeDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame(2.0, $config['default_timeout']);
        self::assertSame(5.0, $config['budget']);
        self::assertSame('', $config['version']);
        self::assertFalse($config['live']['expose_client_ip']);
        self::assertSame(5.0, $config['ready']['public_cache_ttl']);
        self::assertSame('cache.app', $config['ready']['cache_pool']);
        self::assertNull($this->process(['ready' => ['cache_pool' => null]])['ready']['cache_pool']);
        self::assertSame(['public' => false, 'allowed_ips' => ['127.0.0.1', '::1'], 'token' => null, 'token_provider' => null, 'trust_forwarded_ip' => false], $config['details']);
        self::assertFalse($config['probes']['doctrine']['enabled']);
        self::assertFalse($config['probes']['redis']['enabled']);
        self::assertSame(1.0, $config['probes']['redis']['timeout']);
        self::assertFalse($config['probes']['messenger']['enabled']);
        self::assertFalse($config['probes']['messenger']['critical']);
        self::assertTrue($config['probes']['frankenphp']['enabled']);
        self::assertSame(['live'], $config['probes']['frankenphp']['groups']);
        self::assertSame(['enabled' => false, 'token' => null, 'token_provider' => null, 'roles' => [], 'probe_metrics' => true], $config['metrics']);
    }

    #[Test]
    public function providerAloneOrWithEmptyTokenIsAccepted(): void
    {
        $config = $this->process(['details' => ['token' => '', 'token_provider' => 'app.token'], 'metrics' => ['token_provider' => 'app.token']]);

        self::assertSame('app.token', $config['details']['token_provider']);
        self::assertNull($config['details']['token']);
        self::assertSame('app.token', $config['metrics']['token_provider']);
        self::assertSame([], $this->process(['metrics' => ['token' => 'x', 'token_provider' => '']])['metrics']['roles']);
    }

    #[Test]
    public function emptyTokensBecomeNull(): void
    {
        $config = $this->process(['details' => ['token' => ''], 'metrics' => ['token' => '']]);

        self::assertNull($config['details']['token']);
        self::assertNull($config['metrics']['token']);
    }

    #[Test]
    public function fullConfigurationIsAccepted(): void
    {
        $config = $this->process([
            'details' => ['allowed_ips' => ['10.0.0.0/8', 'fd00::/8', '192.0.2.1']],
            'probes'  => [
                'doctrine'  => ['enabled' => true, 'connections' => ['default'], 'timeout' => 1.5],
                'redis'     => ['enabled' => true, 'client' => 'snc_redis.default'],
                'messenger' => ['enabled' => true, 'transports' => ['async' => ['warn_at' => 10, 'fail_at' => 10], 'failed' => []]],
            ],
        ]);

        self::assertSame(['async' => ['warn_at' => 10, 'fail_at' => 10], 'failed' => ['warn_at' => null, 'fail_at' => null]], $config['probes']['messenger']['transports']);
        self::assertSame(1.5, $config['probes']['doctrine']['timeout']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'bad ip' => [['details' => ['allowed_ips' => ['300.1.1.1']]], 'allowed_ips'];
        yield 'bad cidr v4' => [['details' => ['allowed_ips' => ['10.0.0.0/33']]], 'allowed_ips'];
        yield 'bad cidr suffix' => [['details' => ['allowed_ips' => ['10.0.0.0/x']]], 'allowed_ips'];
        yield 'bad cidr v6' => [['details' => ['allowed_ips' => ['::1/129']]], 'allowed_ips'];
        yield 'non string ip' => [['details' => ['allowed_ips' => [12]]], 'allowed_ips'];
        yield 'redis without target' => [['probes' => ['redis' => ['enabled' => true]]], 'exactly one of "dsn" or "client"'];
        yield 'redis with both' => [['probes' => ['redis' => ['enabled' => true, 'dsn' => 'redis://x', 'client' => 'svc']]], 'exactly one of "dsn" or "client"'];
        yield 'messenger without transports' => [['probes' => ['messenger' => ['enabled' => true]]], 'at least one transport'];
        yield 'messenger warn above fail' => [['probes' => ['messenger' => ['transports' => ['async' => ['warn_at' => 5, 'fail_at' => 2]]]]], '"warn_at" must be lower'];
        yield 'messenger zero threshold' => [['probes' => ['messenger' => ['transports' => ['async' => ['warn_at' => 0]]]]], 'expected an integer >= 1'];
        yield 'bad group' => [['probes' => ['frankenphp' => ['groups' => ['startup']]]], 'allowed values are "live" and "ready"'];
        yield 'empty groups' => [['probes' => ['frankenphp' => ['groups' => []]]], 'groups'];
        yield 'zero timeout' => [['probes' => ['redis' => ['timeout' => 0]]], 'greater than 0'];
        yield 'zero budget' => [['budget' => 0], 'budget'];
        yield 'details token and provider' => [['details' => ['token' => 'x', 'token_provider' => 'app.token']], '"nowo_health_kit.details.token" and "nowo_health_kit.details.token_provider" are mutually exclusive'];
        yield 'metrics token and provider' => [['metrics' => ['token' => 'x', 'token_provider' => 'app.token']], '"nowo_health_kit.metrics.token" and "nowo_health_kit.metrics.token_provider" are mutually exclusive'];
        yield 'unknown key' => [['probes' => ['mongo' => []]], 'Unrecognized option "mongo"'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidConfigurationNamesThePath(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($config);
    }

    #[Test]
    public function normalizeGroups(): void
    {
        self::assertSame(['live'], Configuration::normalizeGroups('live'));
        self::assertSame(['ready', 'live'], Configuration::normalizeGroups(['ready', 'live']));
        self::assertNull(Configuration::normalizeGroups([]));
        self::assertNull(Configuration::normalizeGroups(['nope']));
        self::assertNull(Configuration::normalizeGroups([1]));
        self::assertNull(Configuration::normalizeGroups(true));
    }
}
