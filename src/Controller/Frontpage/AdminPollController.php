<?php

declare(strict_types=1);

namespace App\Controller\Frontpage;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Frontpage\Poll;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Frontpage\PollRepository;
use App\Repository\Frontpage\PollRevisionRepository;
use App\Service\Frontpage\PollService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The polls the board has agreed to, with how far each one has got. Taking one down moves its closing date to today
 * rather than deleting it, so what was asked and how it was answered stays on the website.
 */
use function array_values;
use function iterator_to_array;

#[Route(
    path: '/admin/polls',
    name: 'admin/frontpage/polls/',
)]
#[IsGranted(UserRoles::Board->value)]
class AdminPollController extends AbstractController
{
    private const int PAGE_SIZE = 25;

    public function __construct(
        private readonly PollRepository $pollRepository,
        private readonly PollRevisionRepository $revisionRepository,
        private readonly PollService $pollService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{page}',
        name: 'index',
        requirements: ['page' => '\\d+'],
        defaults: ['page' => 1],
    )]
    public function index(int $page): Response
    {
        $paginator = $this->pollRepository->paginateForAdmin(
            $page,
            self::PAGE_SIZE,
        );
        $polls = array_values(iterator_to_array($paginator));
        // The rows show what each question was answered, which is a query per poll unless the page is warmed at once.
        $this->pollRepository->primeResults($polls);

        return $this->render(
            'frontpage/admin/polls/index.html.twig',
            [
                'polls' => $polls,
                'currentPage' => $page,
                'pageSize' => self::PAGE_SIZE,
                'totalCount' => $paginator->count(),
                'awaitingReview' => $this->revisionRepository->countForReview(),
            ],
        );
    }

    #[Route(
        path: '/{poll}/delete',
        name: 'delete',
        requirements: ['poll' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"poll_delete-" ~ args["poll"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function delete(Poll $poll): Response
    {
        $this->pollService->softExpire($poll);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans(
                'The poll is closed. It stays in the archive with the answers it was given.',
            ),
        );

        return $this->redirectToRoute('admin/frontpage/polls/index');
    }
}
