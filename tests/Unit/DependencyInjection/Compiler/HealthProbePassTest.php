<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\DependencyInjection\Compiler;

use InvalidArgumentException;
use Nowo\HealthKitBundle\DependencyInjection\Compiler\HealthProbePass;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use Nowo\HealthKitBundle\Health\RegisteredProbe;
use Nowo\HealthKitBundle\Tests\Fixtures\AutoNamedProbe;
use Nowo\HealthKitBundle\Tests\Fixtures\QueueProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(HealthProbePass::class)]
final class HealthProbePassTest extends TestCase
{
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoHealthKitExtension())->load([['probes' => ['frankenphp' => ['enabled' => false]]]], $container);

        return $container;
    }

    /**
     * @return list<Definition>
     */
    private function registered(ContainerBuilder $container): array
    {
        (new HealthProbePass())->process($container);
        $probes = $container->getDefinition(NowoHealthKitExtension::CHECKER_ID)->getArgument(0);
        self::assertIsArray($probes);
        $out = [];
        foreach ($probes as $probe) {
            self::assertInstanceOf(Definition::class, $probe);
            $out[] = $probe;
        }

        return $out;
    }

    #[Test]
    public function buildsSortedRegisteredProbes(): void
    {
        $container = $this->container();
        $container->register('app.queue', QueueProbe::class)
            ->addTag(NowoHealthKitExtension::PROBE_TAG)
            ->addTag(NowoHealthKitExtension::PROBE_TAG, ['name' => 'payments_api', 'groups' => ['ready'], 'critical' => false, 'timeout' => 1.5]);
        $container->register('app.auto', AutoNamedProbe::class)->addTag(NowoHealthKitExtension::PROBE_TAG, ['groups' => 'live', 'critical' => 0, 'timeout' => 3]);

        $probes = $this->registered($container);

        self::assertCount(2, $probes);
        self::assertSame(RegisteredProbe::class, $probes[0]->getClass());
        self::assertSame(['auto_named', 'app.auto', ['live'], false, 3.0], [$probes[0]->getArgument(0), (string) $probes[0]->getArgument(1), $probes[0]->getArgument(2), $probes[0]->getArgument(3), $probes[0]->getArgument(4)]);
        self::assertSame(['payments_api', ['ready'], false, 1.5], [$probes[1]->getArgument(0), $probes[1]->getArgument(2), $probes[1]->getArgument(3), $probes[1]->getArgument(4)]);
        self::assertSame(NativeClock::class, $container->getDefinition('nowo_health_kit.clock')->getClass());
    }

    #[Test]
    public function usesDefaultTimeoutAndFrameworkClock(): void
    {
        $container = $this->container();
        $container->register('clock', NativeClock::class);
        $container->register('app.probe', QueueProbe::class)->addTag(NowoHealthKitExtension::PROBE_TAG, ['name' => 'x']);

        $probes = $this->registered($container);

        self::assertSame([['ready'], true, 2.0], [$probes[0]->getArgument(2), $probes[0]->getArgument(3), $probes[0]->getArgument(4)]);
        self::assertSame('clock', (string) $container->getAlias('nowo_health_kit.clock'));
    }

    #[Test]
    public function withoutTheCheckerNothingHappens(): void
    {
        $container = new ContainerBuilder();
        (new HealthProbePass())->process($container);

        self::assertFalse($container->has('nowo_health_kit.clock'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidTags(): iterable
    {
        yield 'bad name' => [['name' => 'Bad Name'], 'invalid name'];
        yield 'bad groups' => [['name' => 'x', 'groups' => ['boot']], 'groups must be'];
        yield 'bad timeout' => [['name' => 'x', 'timeout' => 0], 'timeout must be'];
        yield 'string timeout' => [['name' => 'x', 'timeout' => 'fast'], 'timeout must be'];
    }

    /**
     * @param array<string, mixed> $tag
     */
    #[Test]
    #[DataProvider('invalidTags')]
    public function invalidTagsFail(array $tag, string $message): void
    {
        $container = $this->container();
        $container->register('app.probe', QueueProbe::class)->addTag(NowoHealthKitExtension::PROBE_TAG, $tag);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new HealthProbePass())->process($container);
    }

    #[Test]
    public function duplicateNamesFail(): void
    {
        $container = $this->container();
        $container->register('app.a', QueueProbe::class)->addTag(NowoHealthKitExtension::PROBE_TAG, ['name' => 'dup']);
        $container->register('app.b', AutoNamedProbe::class)->addTag(NowoHealthKitExtension::PROBE_TAG, ['name' => 'dup']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Health probe name "dup" is used by both "app.a" and "app.b".');
        (new HealthProbePass())->process($container);
    }

    #[Test]
    public function classWithoutNamespaceGetsANameToo(): void
    {
        $container = $this->container();
        $container->register('app.global', 'GlobalProbe')->addTag(NowoHealthKitExtension::PROBE_TAG);

        self::assertSame('global', $this->registered($container)[0]->getArgument(0));
    }
}
