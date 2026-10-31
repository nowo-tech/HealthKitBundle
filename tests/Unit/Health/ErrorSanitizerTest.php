<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Health;

use Exception;
use Nowo\HealthKitBundle\Health\ErrorSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ErrorSanitizer::class)]
final class ErrorSanitizerTest extends TestCase
{
    #[Test]
    public function redactsCredentialsAndTruncates(): void
    {
        $sanitizer = new ErrorSanitizer();

        self::assertSame('redis://***@cache:6379 password=*** host=db', $sanitizer->redact('redis://default:pa55@cache:6379 password=pa55 host=db'));
        self::assertSame('RuntimeException', $sanitizer->sanitize(new RuntimeException('  ')));
        self::assertSame('RuntimeException: token=***', $sanitizer->sanitize(new RuntimeException('token=abcdef')));
        self::assertSame(300, mb_strlen($sanitizer->sanitize(new RuntimeException(str_repeat('é', 400)))));
    }

    #[Test]
    public function globalExceptionClassesKeepTheirName(): void
    {
        self::assertSame('Exception: boom', (new ErrorSanitizer())->sanitize(new Exception('boom')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        yield 'password with @' => ['redis://:p@ssw0rd@redis:6379', 'redis://***@redis:6379'];
        yield 'password with /' => ['pgsql://u:pa/ss@db:5432/app', 'pgsql://***@db:5432/app'];
        yield 'yaml' => ['password: "s3cret"', 'password: "***"'];
        yield 'json' => ['{"password":"s3cret","user":"a"}', '{"password":"***","user":"a"}'];
        yield 'php array' => ["'password' => 's3cret'", "'password' => '***'"];
        yield 'env' => ['PGPASSWORD=s3cret psql', 'PGPASSWORD=*** psql'];
        yield 'api keys' => ['api_key=abc client_secret=xyz access_token=tok', 'api_key=*** client_secret=*** access_token=***'];
        yield 'bearer' => ['sent Bearer abc.def-ghi=', 'sent Bearer ***'];
        yield 'scheme-less userinfo' => ['connect app:s3cret@db:5432 failed', 'connect ***@db:5432 failed'];
        yield 'harmless' => ['Connection refused [tcp://redis:6379] for user@example.com', 'Connection refused [tcp://redis:6379] for user@example.com'];
    }

    #[Test]
    #[DataProvider('secrets')]
    public function redactsCommonCredentialShapes(string $input, string $expected): void
    {
        self::assertSame($expected, (new ErrorSanitizer())->redact($input));
    }

    #[Test]
    public function redactsDataBySecretKeyAndValue(): void
    {
        self::assertSame(
            ['client_secret' => '***', 'dsn' => 'amqp://***@mq', 'n' => 1, 'ok' => true, 'none' => null],
            (new ErrorSanitizer())->redactData(['client_secret' => 'x', 'dsn' => 'amqp://u:p@mq', 'n' => 1, 'ok' => true, 'none' => null]),
        );
    }
}
