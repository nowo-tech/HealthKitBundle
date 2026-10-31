<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

use Throwable;

use function mb_substr;
use function preg_replace;
use function sprintf;
use function strrchr;
use function substr;
use function trim;

/**
 * Turns a probe exception into a short message that is safe to show to detail callers and to log.
 *
 * It keeps the exception short class name and message, removes credentials embedded in URLs or DSNs
 * (`scheme://user:pass@host` and `password=…` pairs) and truncates the result.
 */
final class ErrorSanitizer
{
    private const MAX_LENGTH = 300;

    /**
     * Builds the sanitized message.
     *
     * @param Throwable $error exception thrown by a probe
     *
     * @return string `ShortClass: message` without credentials, at most 300 characters
     */
    public function sanitize(Throwable $error): string
    {
        $class   = strrchr($error::class, '\\');
        $short   = $class === false ? $error::class : substr($class, 1);
        $message = $this->redact(trim($error->getMessage()));

        $text = $message === '' ? $short : sprintf('%s: %s', $short, $message);

        return mb_substr($text, 0, self::MAX_LENGTH);
    }

    /**
     * Removes credentials from free text.
     *
     * @param string $text text that may contain a DSN or a `password=` pair
     *
     * @return string the redacted text
     */
    public function redact(string $text): string
    {
        $text = (string) preg_replace('#([a-z][a-z0-9+.\-]*://)[^\s/@]+@#i', '$1***@', $text);

        return (string) preg_replace('#\b(password|passwd|pwd|pass|auth|token|secret)=([^\s;&]+)#i', '$1=***', $text);
    }
}
