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

use function strlen;

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

    #[Test]
    public function redactsEmailsAndIpAddresses(): void
    {
        $sanitizer = new ErrorSanitizer();

        self::assertSame('mail ***@*** ip ***, *** and *** at 10:20:30 (999.1.1.1)', $sanitizer->redactPersonalData('mail jane.doe+x@mail.example.com ip 10.1.2.3, 2001:db8::1 and ::1 at 10:20:30 (999.1.1.1)'));
        self::assertSame('plain text', $sanitizer->redactPersonalData('plain text'));
    }

    #[Test]
    public function redactsIpAddressesWithPortsAndPrefixes(): void
    {
        $sanitizer = new ErrorSanitizer();

        self::assertSame('redis ***:6379 tcp:*** redis://***:6379 [***]:6379 at ***.', $sanitizer->redactPersonalData('redis 10.0.0.9:6379 tcp:10.0.0.7 redis://10.0.0.9:6379 [2001:db8::1]:6379 at 10.0.0.1.'));
        self::assertSame('at 10:20:30 and 1.2.3.4.5 and v10.0.0.9', $sanitizer->redactPersonalData('at 10:20:30 and 1.2.3.4.5 and v10.0.0.9'));
    }

    #[Test]
    public function hugeInputsAreBoundedAndFinishFast(): void
    {
        $sanitizer = new ErrorSanitizer();
        $huge      = str_repeat('password=', 12000) . str_repeat('a:b ', 5000);

        $start = microtime(true);
        $sanitizer->redact($huge);
        $sanitizer->redactPersonalData($huge);
        $sanitizer->sanitize(new RuntimeException($huge));
        $sanitizer->redactData(['k' => $huge]);

        self::assertLessThan(1.0, microtime(true) - $start);
        self::assertLessThanOrEqual(ErrorSanitizer::MAX_INPUT_LENGTH, strlen($sanitizer->redact($huge)));
        self::assertSame('short text', $sanitizer->redact('short text'));
        self::assertSame(ErrorSanitizer::MAX_INPUT_LENGTH, strlen($sanitizer->redact(str_repeat('x', 5000))));
    }

    #[Test]
    public function aSecretAtTheBoundaryNeverComesOutUnredacted(): void
    {
        $sanitizer = new ErrorSanitizer();
        for ($pad = 1960; $pad <= 2000; ++$pad) {
            $text   = str_repeat('x', $pad - 1) . ' password=hunter2secret redis://user:pass-REDACTED-TEST@host ' . str_repeat('y', 100);
            $result = $sanitizer->redact($text);
            self::assertStringNotContainsString('hunter2', $result, (string) $pad);
            self::assertStringNotContainsString('pass-REDACTED', $result, (string) $pad);
            self::assertStringNotContainsString('user:', $result, (string) $pad);
        }
    }
}
