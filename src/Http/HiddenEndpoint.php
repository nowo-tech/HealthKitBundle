<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

use function is_string;
use function sprintf;

/**
 * "Not found" answer for a non-public health endpoint (`live.public` / `ready.public` false).
 *
 * The exception mirrors the one the router throws for an unknown URL (same class, same message shape, a
 * {@see ResourceNotFoundException} as previous), so the host's error renderer answers exactly as it does for any
 * path that has no route: the caller cannot tell the endpoint exists.
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

        return new NotFoundHttpException($message, new ResourceNotFoundException(sprintf('No routes found for "%s".', $request->getPathInfo())));
    }
}
