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
     * Inputs use the `{v}` placeholder; the credential is built at runtime so no literal secret lives in the repo.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        yield 'credential with @' => ['redis://:{v}@{v}@redis:6379', 'redis://***@redis:6379'];
        yield 'credential with /' => ['pgsql://u:{v}/{v}@db:5432/app', 'pgsql://***@db:5432/app'];
        yield 'yaml' => ['password: "{v}"', 'password: "***"'];
        yield 'json' => ['{"password":"{v}","user":"a"}', '{"password":"***","user":"a"}'];
        yield 'php array' => ["'password' => '{v}'", "'password' => '***'"];
        yield 'env' => ['PGPASSWORD={v} psql', 'PGPASSWORD=*** psql'];
        yield 'api keys' => ['api_key={v} client_secret={v} access_token={v}', 'api_key=*** client_secret=*** access_token=***'];
        yield 'bearer' => ['sent Bearer {v}', 'sent Bearer ***'];
        yield 'scheme-less userinfo' => ['connect app:{v}@db:5432 failed', 'connect ***@db:5432 failed'];
        yield 'harmless' => ['Connection refused [tcp://redis:6379] for user@example.com', 'Connection refused [tcp://redis:6379] for user@example.com'];
    }

    #[Test]
    #[DataProvider('secrets')]
    public function redactsCommonCredentialShapes(string $input, string $expected): void
    {
        $value = implode('-', ['REDACTED', 'TEST', bin2hex(random_bytes(3))]);

        self::assertSame($expected, (new ErrorSanitizer())->redact(str_replace('{v}', $value, $input)));
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
