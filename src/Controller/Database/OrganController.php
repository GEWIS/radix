<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Service\Database\Meeting as MeetingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/bodies')]
#[IsGranted(UserRoles::DatabaseReadOnly->value)]
final class OrganController extends AbstractController
{
    public function __construct(private readonly MeetingService $meetingService)
    {
    }

    /**
     * The organs a decision can be taken about.
     */
    #[Route(
        path: '/search',
        name: 'decision_organ_search',
        methods: ['GET'],
    )]
    public function search(Request $request): JsonResponse
    {
        return $this->json($this->meetingService->searchOrgans((string) $request->query->get('q', '')));
    }

    #[Route(
        path: '/info/{type}/{number}/{point}/{decision}/{sequence}',
        name: 'decision_organ_info',
        requirements: [
            'type' => 'ALV|BV|VV|Virt',
            'number' => '\d+',
            'point' => '\d+',
            'decision' => '\d+',
            'sequence' => '\d+',
        ],
        methods: ['GET'],
    )]
    public function info(
        MeetingTypes $type,
        int $number,
        int $point,
        int $decision,
        int $sequence,
    ): JsonResponse {
        $organ = $this->meetingService->getOrganInfo(
            $type,
            $number,
            $point,
            $decision,
            $sequence,
        );

        if (null === $organ) {
            throw $this->createNotFoundException();
        }

        return $this->json($organ);
    }
}
