<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Http\DetailsTokenProviderInterface;
use Nowo\HealthKitBundle\Metrics\MetricsTokenProviderInterface;
use RuntimeException;

/**
 * Token provider whose value can change between requests (like editable settings); counts lookups.
 */
final class MutableTokenProvider implements MetricsTokenProviderInterface, DetailsTokenProviderInterface
{
    public int $calls = 0;

    public function __construct(public ?string $token = null, public bool $fail = false)
    {
    }

    public function getToken(): ?string
    {
        ++$this->calls;
        if ($this->fail) {
            throw new RuntimeException('settings store unavailable');
        }

        return $this->token;
    }
}
