<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Service\Application\RealtimeAuthorization;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hands the browser a fresh subscribe cookie, so a tab whose own has expired need not reload the page for one.
 *
 * Open to anyone: what it grants is read from whoever is asking.
 */
final class RealtimeController extends AbstractController
{
    public function __construct(private readonly RealtimeAuthorization $realtime)
    {
    }

    #[Route(
        path: '/realtime/grant',
        name: 'app_realtime_grant',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $this->realtime->authorize($this->realtime->grants());

        $response = new Response(
            null,
            Response::HTTP_NO_CONTENT,
        );
        $response->headers->set(
            'Cache-Control',
            'no-store',
        );

        return $response;
    }
}
