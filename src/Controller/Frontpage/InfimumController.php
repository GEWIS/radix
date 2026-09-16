<?php

declare(strict_types=1);

namespace App\Controller\Frontpage;

use App\Entity\User\Enums\UserRoles;
use App\Service\Frontpage\InfimumService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The infimum, requested by the page after it has been rendered rather than while it is being rendered. It comes
 * from the Supremum's own API, and the footer is on every page of this website: fetched inline, a slow response from
 * an external server would slow down every page here.
 *
 * The cron keeps the cache filled, so this is usually served from it; a cold cache fetches once, off the render path.
 */
#[IsGranted(UserRoles::User->value)]
class InfimumController extends AbstractController
{
    public function __construct(private readonly InfimumService $infimumService)
    {
    }

    #[Route(
        path: '/infimum',
        name: 'infimum',
        methods: ['GET'],
    )]
    public function show(): JsonResponse
    {
        return new JsonResponse(['content' => $this->infimumService->getInfimum()]);
    }
}
