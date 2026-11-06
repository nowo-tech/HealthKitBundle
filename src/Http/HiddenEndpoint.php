<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

use function in_array;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * "Not found" answer for a non-public health endpoint (`live.public` / `ready.public` false).
 *
 * The exception mirrors the one the router throws for an unknown URL (same class, same message shape, a
 * {@see ResourceNotFoundException} as previous), so the host's error renderer answers exactly as it does for any
 * path that has no route: the caller cannot tell the endpoint exists. The previous exception carries the path the
 * redirectable matcher reports last: for GET / HEAD it retries with the trailing slash toggled (`/health/ready` →
 * `/health/ready/`, `/health/ready/` → `/health/ready`), other methods keep the path as sent.
 */
final class HiddenEndpoint
{
    /**
     * Builds the exception for a caller the details rules do not allow.
     *
     * @param Request $request incoming request
     *
     * @return NotFoundHttpException the router-like 404
     */
    public static function notFound(Request $request): NotFoundHttpException
    {
        $message = sprintf('No route found for "%s %s"', $request->getMethod(), $request->getUriForPath($request->getPathInfo()));
        $referer = $request->headers->get('referer');
        if (is_string($referer) && $referer !== '') {
            $message .= sprintf(' (from "%s")', $referer);
        }

        $path = $request->getPathInfo();
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && '' !== $trimmed = rtrim($path, '/')) {
            $path = $trimmed === $path ? $path . '/' : $trimmed;
        }

        return new NotFoundHttpException($message, new ResourceNotFoundException(sprintf('No routes found for "%s".', $path)));
    }
}
