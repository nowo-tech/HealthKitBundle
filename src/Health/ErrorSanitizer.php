<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

use Closure;
use Throwable;

use function filter_var;
use function is_string;
use function mb_strcut;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function strcspn;
use function strlen;
use function strrchr;
use function strrev;
use function substr;
use function trim;

use const FILTER_VALIDATE_IP;

/**
 * Turns a probe exception into a short message that is safe to show to detail callers and to log.
 *
 * It keeps the exception short class name and message, removes credentials (see {@see redact()}) and truncates the
 * result. The same redaction applies to host probe messages and data shown to detail callers.
 */
final class ErrorSanitizer
{
    private const MAX_LENGTH = 300;

    /**
     * Length in bytes above which {@see redact()} and {@see redactPersonalData()} truncate (audits QH-D5, QH-D6).
     *
     * Truncated output is at most this long. Shorter text is not cut, and redaction can make it slightly longer, for
     * example when a one-character URL user name becomes `***` (audit QH-I6).
     *
     * @var int
     */
    public const MAX_INPUT_LENGTH = 2000;

    /** @var string appended when the text was truncated (audit QH-I5) */
    public const TRUNCATED_MARKER = '…[truncated]';

    /** Extra bytes the regexes see past {@see MAX_INPUT_LENGTH}, so a credential near the cut keeps its terminator. */
    private const LOOKAHEAD_LENGTH = 512;

    /** Bytes matched by PCRE `\s` without the `u` modifier. */
    private const WHITESPACE = " \t\n\v\f\r";

    /** Characters that end a token when the kept text has no whitespace. */
    private const TOKEN_SEPARATORS = "\"',;&|";

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
        return $this->bounded($text, $this->redactCredentials(...));
    }

    /**
     * Applies the credential patterns of {@see redact()} to already bounded text.
     *
     * @param string $text text of at most {@see MAX_INPUT_LENGTH} + {@see LOOKAHEAD_LENGTH} bytes
     *
     * @return string the redacted text
     */
    private function redactCredentials(string $text): string
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
     * Removes personal data from free text: email addresses and IPv4/IPv6 addresses.
     *
     * Complements {@see redact()} (credentials only) for text that is stored or logged for a long time, such as the
     * worker recycle reason.
     *
     * @param string $text free text
     *
     * @return string the text with `***@***` for emails and `***` for IP addresses
     */
    public function redactPersonalData(string $text): string
    {
        return $this->bounded($text, $this->redactPersonalDataPatterns(...));
    }

    /**
     * Applies the personal-data patterns of {@see redactPersonalData()} to already bounded text.
     *
     * @param string $text text of at most {@see MAX_INPUT_LENGTH} + {@see LOOKAHEAD_LENGTH} bytes
     *
     * @return string the redacted text
     */
    private function redactPersonalDataPatterns(string $text): string
    {
        $text = (string) preg_replace('#[\w.%+-]+@[\w-]+(?:\.[\w-]+)+#u', '***@***', $text);

        return (string) preg_replace_callback(
            '#(?<![\w.])\d{1,3}(?:\.\d{1,3}){3}(?!\w|\.\d)|(?<![\w.:])[0-9a-f:]*:[0-9a-f:]*(?:\.\d{1,3}){0,3}(?![\w:])#i',
            static fn (array $m): string => filter_var($m[0], FILTER_VALIDATE_IP) === false ? $m[0] : '***',
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

    /**
     * Redacts long text without quadratic regex time and without leaking a credential at the cut (audits QH-D5, QH-D6).
     *
     * Text up to {@see MAX_INPUT_LENGTH} bytes is redacted as is. Longer text is first cut to {@see MAX_INPUT_LENGTH}
     * + {@see LOOKAHEAD_LENGTH} bytes, so the regexes stay bounded but still see the terminator a credential near the
     * limit needs (the `@` of a URL, the closing quote of a JSON value). Only the redacted result is then cut to
     * {@see MAX_INPUT_LENGTH} bytes, including the {@see TRUNCATED_MARKER}: back to the last whitespace, or, when the
     * kept part has none, back past the trailing token (which ends at one of `"',;&|`). A credential longer than the
     * lookahead that straddles the window is therefore dropped, with one known limit (audit QH-D7, fix planned for
     * 1.3.x): when the kept part has no whitespace and such a credential contains one of `"',;&|`, the token step stops
     * inside it and its prefix (user name, start of the password) can be shown. Cuts fall on UTF-8 character
     * boundaries.
     *
     * @param string $text text of any length
     * @param Closure(string):string $redactor patterns to apply to the bounded text
     *
     * @return string the redacted text: at most {@see MAX_INPUT_LENGTH} bytes when truncated; text that was not
     *                truncated can grow slightly through redaction (audit QH-I6)
     */
    private function bounded(string $text, Closure $redactor): string
    {
        if (strlen($text) <= self::MAX_INPUT_LENGTH) {
            return $redactor($text);
        }

        $redacted = $redactor(mb_strcut($text, 0, self::MAX_INPUT_LENGTH + self::LOOKAHEAD_LENGTH));
        $kept     = mb_strcut($redacted, 0, self::MAX_INPUT_LENGTH - strlen(self::TRUNCATED_MARKER));
        $reversed = strrev($kept);
        $tail     = strcspn($reversed, self::WHITESPACE);
        if ($tail === strlen($kept)) {
            $tail = strcspn($reversed, self::WHITESPACE . self::TOKEN_SEPARATORS);
            $kept = substr($kept, 0, strlen($kept) - $tail);
        } else {
            $kept = substr($kept, 0, strlen($kept) - $tail - 1);
        }

        return $kept . self::TRUNCATED_MARKER;
    }
}
