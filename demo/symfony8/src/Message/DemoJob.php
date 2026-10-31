<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Demo message routed to the `async` transport so the Messenger probe has a queue depth to report.
 */
final readonly class DemoJob
{
    public function __construct(public int $number)
    {
    }
}
