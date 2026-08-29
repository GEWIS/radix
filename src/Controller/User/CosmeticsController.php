<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\User\UserSettingsRepository;
use App\Service\User\UserSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Whether this member is shown the seasonal decorations, flipped from the switch in the navbar.
 *
 * It answers outside `/user/settings`, where a member's other preferences live, because the switch posts with `fetch`
 * from every page and everything under there is behind sudo: a lapsed grant would answer with the confirmation page
 * and the switch would spring back without saying why.
 */
#[IsGranted(
    attribute: UserRoles::User->value,
    message: 'You are not allowed to change these settings.',
)]
#[IsCsrfTokenValid(
    id: 'cosmetics',
    tokenKey: '_csrf_token',
)]
#[Route(
    path: '/user/cosmetics',
    name: 'user_cosmetics',
    methods: ['POST'],
)]
final class CosmeticsController extends AbstractController
{
    public function __construct(
        private readonly UserSettingsRepository $settingsRepository,
        private readonly UserSettingsService $userSettingsService,
    ) {
    }

    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $user,
    ): Response {
        $this->userSettingsService->setCosmeticsDisabled(
            $this->settingsRepository->getOrCreateForUser($user),
            $request->request->getBoolean('disabled'),
        );

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
