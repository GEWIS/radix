<?php

declare(strict_types=1);

namespace App\Controller\Application;

use App\Entity\User\Enums\UserRoles;
use App\Security\User\SudoVoter;
use App\Service\Application\TransportStatusProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What the message transports are holding, for an administrator who would otherwise have to open a shell on a
 * container to run `messenger:stats`. Read-only: nothing here acknowledges, retries or removes a message. Behind
 * sudo all the same, because a failed message names the work and the error that broke it, which is as much of the
 * application's insides as the rest of this section shows.
 *
 * The failure list is `Application:Admin:FailedMessageOverview`, which pages over it like every other overview.
 */
#[IsGranted(
    attribute: UserRoles::Admin->value,
    message: 'You are not allowed to inspect the message queues.',
)]
#[IsGranted(SudoVoter::ATTRIBUTE)]
class QueueController extends AbstractController
{
    public function __construct(private readonly TransportStatusProvider $transportStatusProvider)
    {
    }

    #[Route(
        path: '/admin/queues',
        name: 'admin/queues',
        methods: ['GET'],
    )]
    public function index(): Response
    {
        return $this->render(
            'application/admin/queues.html.twig',
            ['transports' => $this->transportStatusProvider->transports()],
        );
    }
}
