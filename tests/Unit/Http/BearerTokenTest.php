<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Http;

use Nowo\HealthKitBundle\Http\BearerToken;
use Nowo\HealthKitBundle\Tests\Fixtures\MutableTokenProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(BearerToken::class)]
final class BearerTokenTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnop-REDACTED-TEST';

    /**
     * @return iterable<string, array{array<string, string>, string|null, bool}>
     */
    public static function cases(): iterable
    {
        yield 'match' => [['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN], self::TOKEN, true];
        yield 'case-insensitive scheme' => [['HTTP_AUTHORIZATION' => 'bearer ' . self::TOKEN], self::TOKEN, true];
        yield 'wrong token' => [['HTTP_AUTHORIZATION' => 'Bearer nope-nope-nope-nope'], self::TOKEN, false];
        yield 'empty bearer' => [['HTTP_AUTHORIZATION' => 'Bearer   '], self::TOKEN, false];
        yield 'basic scheme' => [['HTTP_AUTHORIZATION' => 'Basic ' . self::TOKEN], self::TOKEN, false];
        yield 'no header' => [[], self::TOKEN, false];
        yield 'not configured' => [['HTTP_AUTHORIZATION' => 'Bearer '], null, false];
        yield 'too short' => [['HTTP_AUTHORIZATION' => 'Bearer short'], 'short', false];
    }

    /**
     * @param array<string, string> $server
     */
    #[Test]
    #[DataProvider('cases')]
    public function matchesTheBearerHeader(array $server, ?string $expected, bool $result): void
    {
        self::assertSame($result, BearerToken::matches(Request::create('/', server: $server), $expected));
    }

    #[Test]
    public function queryTokensAreIgnored(): void
    {
        self::assertFalse(BearerToken::matches(Request::create('/?token=' . self::TOKEN), self::TOKEN));
    }

    #[Test]
    public function isConfigured(): void
    {
        self::assertFalse(BearerToken::isConfigured(null));
        self::assertFalse(BearerToken::isConfigured('fifteen-chars!!'));
        self::assertTrue(BearerToken::isConfigured('sixteen-chars!!!'));
    }

    #[Test]
    public function resolvePrefersTheProviderAndFailsClosed(): void
    {
        self::assertSame('static-token', BearerToken::resolve('static-token', null));
        self::assertSame('runtime-token', BearerToken::resolve(null, new MutableTokenProvider('runtime-token')));
        self::assertNull(BearerToken::resolve('static-token', new MutableTokenProvider()));
        self::assertNull(BearerToken::resolve(null, new MutableTokenProvider('x', true)));
    }

    #[Test]
    public function extractReadsOnlyANonEmptyBearerHeader(): void
    {
        self::assertSame('abc', BearerToken::extract(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'bearer  abc '])));
        self::assertNull(BearerToken::extract(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer   '])));
        self::assertNull(BearerToken::extract(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Basic abc'])));
        self::assertNull(BearerToken::extract(Request::create('/?token=abc')));
    }
}
