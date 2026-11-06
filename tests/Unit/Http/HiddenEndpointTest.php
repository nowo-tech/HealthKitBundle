<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Http;

use Nowo\HealthKitBundle\Http\HiddenEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

#[CoversClass(HiddenEndpoint::class)]
final class HiddenEndpointTest extends TestCase
{
    #[Test]
    public function mirrorsTheRouterNotFound(): void
    {
        $e = HiddenEndpoint::notFound(Request::create('http://example.test/health/ready'));

        self::assertSame(404, $e->getStatusCode());
        self::assertSame('No route found for "GET http://example.test/health/ready"', $e->getMessage());
        self::assertInstanceOf(ResourceNotFoundException::class, $e->getPrevious());
        // GET / HEAD: the redirectable matcher last tried the slash-toggled path.
        self::assertSame('No routes found for "/health/ready/".', $e->getPrevious()->getMessage());
        self::assertSame('No routes found for "/health/ready".', HiddenEndpoint::notFound(Request::create('/health/ready/'))->getPrevious()?->getMessage());
        self::assertSame('No routes found for "/health/ready".', HiddenEndpoint::notFound(Request::create('/health/ready//'))->getPrevious()?->getMessage());
        self::assertSame('No routes found for "/".', HiddenEndpoint::notFound(Request::create('/'))->getPrevious()?->getMessage());
        // Other methods keep the path as sent.
        self::assertSame('No routes found for "/health/%72eady".', HiddenEndpoint::notFound(Request::create('/health/%72eady', 'POST'))->getPrevious()?->getMessage());

        $referred = HiddenEndpoint::notFound(Request::create('http://example.test/health/live', 'HEAD', server: ['HTTP_REFERER' => 'https://elsewhere.test/']));
        self::assertSame('No route found for "HEAD http://example.test/health/live" (from "https://elsewhere.test/")', $referred->getMessage());
    }
}
