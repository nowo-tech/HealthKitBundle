<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Runtime\WorkerRecyclerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Host controller for the worker recycle tests: `?fail=lost` throws a lost connection (wrapped), `?fail=other` an
 * unrelated error, `?fail=caught` asks for a recycle through the port and answers 200.
 */
#[AsController]
final readonly class ConnectionController
{
    public function __construct(private ?WorkerRecyclerInterface $recycler = null)
    {
    }

    public function __invoke(Request $request): Response
    {
        return match ($request->query->get('fail')) {
            'lost'   => throw new RuntimeException('Session write failed', 0, new LostConnectionException('server went away')),
            'other'  => throw new RuntimeException('Unrelated failure'),
            'caught' => $this->caught(),
            default  => new Response('ok'),
        };
    }

    private function caught(): Response
    {
        $this->recycler?->requestRecycle('quota client lost its connection');

        return new Response('degraded');
    }
}
