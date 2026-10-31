<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Health;

use Exception;
use Nowo\HealthKitBundle\Health\ErrorSanitizer;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
