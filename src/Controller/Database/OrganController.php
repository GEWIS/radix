<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Decision\OrganRepository;
use App\Service\Database\Meeting as MeetingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/bodies')]
#[IsGranted(UserRoles::DatabaseReadOnly->value)]
final class OrganController extends AbstractController
{
    public function __construct(
        private readonly MeetingService $meetingService,
        private readonly OrganRepository $organRepository,
    ) {
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

    /**
     * Where a body used to be read at.
     *
     * The ledger addresses a body by the subdecision that founded it, which is why this address is a subdecision's.
     * Its composition and its page are one page now, addressed by the body's own id, so this sends the reader there
     * rather than showing half of it again.
     */
    #[Route(
        path: '/{type}/{number}/{point}/{decision}/{sequence}',
        name: 'decision_organ_view',
        requirements: [
            'type' => 'ALV|BV|VV|Virt',
            'number' => '-?\\d+',
            'point' => '\\d+',
            'decision' => '\\d+',
            'sequence' => '\\d+',
        ],
        methods: ['GET'],
    )]
    public function view(
        MeetingTypes $type,
        int $number,
        int $point,
        int $decision,
        int $sequence,
    ): Response {
        $foundation = $this->meetingService->findFoundation(
            $type,
            $number,
            $point,
            $decision,
            $sequence,
        );

        if (null === $foundation) {
            throw $this->createNotFoundException();
        }

        $organ = $this->organRepository->findByFoundation($foundation);

        if (null === $organ) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute(
            'admin/bodies/view',
            ['organ' => $organ->getId()],
        );
    }

    #[Route(
        path: '/info/{type}/{number}/{point}/{decision}/{sequence}',
        name: 'decision_organ_info',
        requirements: [
            'type' => 'ALV|BV|VV|Virt',
            'number' => '-?\d+',
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
