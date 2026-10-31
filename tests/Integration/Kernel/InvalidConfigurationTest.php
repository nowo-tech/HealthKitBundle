<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * REQ-SF-006: invalid configuration fails the container compilation with a message naming the path.
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    #[Test]
    public function unknownDoctrineConnectionFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown Doctrine connection(s) "reporting"');
        $this->bootKernel(
            ['probes' => ['doctrine' => ['enabled' => true, 'connections' => ['reporting']]]],
            ['doctrine' => ['dbal' => ['url' => 'sqlite:///:memory:']]],
            withDoctrine: true,
        );
    }

    #[Test]
    public function doctrineProbeWithoutDoctrineBundleFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Doctrine DBAL connection is configured');
        $this->bootKernel(['probes' => ['doctrine' => ['enabled' => true]]]);
    }

    #[Test]
    public function invalidAllowedIpFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_health_kit.details.allowed_ips');
        $this->bootKernel(['details' => ['allowed_ips' => ['not-an-ip']]]);
    }

    #[Test]
    public function unknownKeyFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "detail"');
        $this->bootKernel(['detail' => []]);
    }
}
