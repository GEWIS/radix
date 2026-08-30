<?php

declare(strict_types=1);

namespace App\Controller\Application;

use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a one-use token out of referrers, caches and history.
 */
trait NoLeakHeadersTrait
{
    private function withNoLeakHeaders(Response $response): Response
    {
        $response->headers->set(
            'Referrer-Policy',
            'no-referrer',
        );
        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, private',
        );
        $response->headers->set(
            'Pragma',
            'no-cache',
        );

        return $response;
    }
}
