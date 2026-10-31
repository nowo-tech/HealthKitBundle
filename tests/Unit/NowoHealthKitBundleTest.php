<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit;

use Nowo\HealthKitBundle\DependencyInjection\Compiler\DoctrineProbePass;
use Nowo\HealthKitBundle\DependencyInjection\Compiler\HealthProbePass;
use Nowo\HealthKitBundle\DependencyInjection\NowoHealthKitExtension;
use Nowo\HealthKitBundle\NowoHealthKitBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;

#[CoversClass(NowoHealthKitBundle::class)]
final class NowoHealthKitBundleTest extends TestCase
{
    #[Test]
    public function registersPassesExtensionAndPackageRootPath(): void
    {
        $bundle    = new NowoHealthKitBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);

        $passes = array_map(static fn (object $p): string => $p::class, $container->getCompilerPassConfig()->getBeforeOptimizationPasses());
        self::assertContains(DoctrineProbePass::class, $passes);
        self::assertContains(HealthProbePass::class, $passes);
        self::assertLessThan(array_search(HealthProbePass::class, $passes, true), array_search(DoctrineProbePass::class, $passes, true));
        self::assertInstanceOf(NowoHealthKitExtension::class, $bundle->getContainerExtension());
        self::assertSame(dirname(__DIR__, 2), $bundle->getPath());
        self::assertFileExists($bundle->getPath() . '/config/routes/health.yaml');
    }
}
