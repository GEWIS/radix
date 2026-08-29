<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\MaintenanceWindow;
use App\Entity\User\Enums\UserRoles;
use App\Form\Application\MaintenanceType;
use App\Repository\Application\MaintenanceWindowRepository;
use App\Service\Application\MaintenanceWindowService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(
    attribute: UserRoles::Admin->value,
    message: 'You are not allowed to manage maintenance mode.',
)]
class MaintenanceController extends AbstractController
{
    public function __construct(
        private readonly MaintenanceWindowRepository $maintenanceWindowRepository,
        private readonly MaintenanceWindowService $maintenanceWindowService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/maintenance',
        name: 'admin/maintenance',
        methods: ['GET'],
    )]
    public function index(): Response
    {
        return $this->render(
            'frontpage/admin/maintenance.html.twig',
            [
                'windows' => $this->maintenanceWindowRepository->findAllOrdered(),
                'now' => new DateTimeImmutable(),
            ],
        );
    }

    #[Route(
        path: '/admin/maintenance/create',
        name: 'admin/maintenance/create',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function create(Request $request): Response
    {
        $window = new MaintenanceWindow();
        $form = $this->createForm(
            MaintenanceType::class,
            $window,
        )->handleRequest($request);

        if (
            !$form->isSubmitted()
            || !$form->isValid()
        ) {
            return $this->render(
                'frontpage/admin/maintenance-window.html.twig',
                ['form' => $form],
            );
        }

        $this->maintenanceWindowService->schedule($window);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('Maintenance window scheduled.'),
        );

        return $this->redirectToRoute('admin/maintenance');
    }

    #[IsCsrfTokenValid(
        id: 'maintenance_window_delete',
        tokenKey: '_csrf_token',
    )]
    #[Route(
        path: '/admin/maintenance/{id}/delete',
        name: 'admin/maintenance/delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(int $id): Response
    {
        if ($this->maintenanceWindowService->remove($id)) {
            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('Maintenance window removed.'),
            );
        }

        return $this->redirectToRoute('admin/maintenance');
    }
}
