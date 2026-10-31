<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

use Throwable;

use function is_string;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strrchr;
use function substr;
use function trim;

/**
 * Turns a probe exception into a short message that is safe to show to detail callers and to log.
 *
 * It keeps the exception short class name and message, removes credentials (see {@see redact()}) and truncates the
 * result. The same redaction applies to host probe messages and data shown to detail callers.
 */
final class ErrorSanitizer
{
    private const MAX_LENGTH = 300;

    /** Regex fragment matching secret-looking keys. */
    private const SECRET_KEY = '[a-z0-9_.-]*(?:password|passwd|passphrase|pwd|secret|token|api[_-]?key|auth[a-z_]*|credentials?)[a-z0-9_.-]*';

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
     * Covered shapes (audit BX L5): `Bearer`/`Basic` credentials; URL userinfo up to the last `@` of the token
     * (`redis://:p@ss@host`, `pgsql://u:pa/ss@db`); scheme-less `user:pass@host`; `key=value`, `key: value`,
     * `"key":"value"` and `'key' => 'value'` pairs whose key contains password/passwd/passphrase/pwd/secret/token/
     * api key/auth/credential (`PGPASSWORD=`, `client_secret=`, `access_token=`…).
     *
     * @param string $text text that may contain a DSN or a credential pair
     *
     * @return string the redacted text
     */
    public function redact(string $text): string
    {
        $text = (string) preg_replace('#\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+/=]+#i', '$1 ***', $text);
        $text = (string) preg_replace('#([a-z][a-z0-9+.\-]*://)\S*@#i', '$1***@', $text);
        $text = (string) preg_replace('#(?<![\w.%+/:@-])[\w.%+-]+:(?!//)[^\s@]+@(?=[\w\[])#', '***@', $text);

        return (string) preg_replace(
            '#(["\']?)\b(' . self::SECRET_KEY . ')\1(\s*(?:=>|=|:)\s*)(["\']?)[^\s"\',;&}\])]+\4#i',
            '$1$2$1$3$4***$4',
            $text,
        );
    }

    /**
     * Redacts probe data: string values go through {@see redact()}, values under a secret-looking key become `***`.
     *
     * @param array<string, bool|float|int|string|null> $data probe data
     *
     * @return array<string, bool|float|int|string|null> redacted data
     */
    public function redactData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('#^' . self::SECRET_KEY . '$#i', $key) === 1) {
                $data[$key] = '***';
            } elseif (is_string($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
