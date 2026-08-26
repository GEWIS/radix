<?php

declare(strict_types=1);

namespace App\Controller\Frontpage;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Frontpage\NewsItem;
use App\Entity\User\Enums\UserRoles;
use App\Form\Frontpage\NewsItemType;
use App\Service\Frontpage\NewsAdminService;
use DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The news the association puts out. Written directly rather than through the review workflow: what the board says is
 * the board's to say, and it says it in its own name.
 */
#[Route(
    path: '/admin/news',
    name: 'admin/frontpage/news/',
)]
#[IsGranted(UserRoles::Board->value)]
class AdminNewsController extends AbstractController
{
    public function __construct(
        private readonly NewsAdminService $newsAdminService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '',
        name: 'index',
    )]
    public function index(): Response
    {
        // The table itself is `Frontpage:Admin:NewsOverview`, which pages over the items on its own.
        return $this->render('frontpage/admin/news/index.html.twig');
    }

    #[Route(
        path: '/create',
        name: 'create',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function create(Request $request): Response
    {
        $item = new NewsItem();
        $item->setDate(new DateTime('today'));
        $item->setPinned(false);

        $form = $this->createForm(
            NewsItemType::class,
            $item,
        )->handleRequest($request);

        if (
            !$form->isSubmitted()
            || !$form->isValid()
        ) {
            return $this->render(
                'frontpage/admin/news/create.html.twig',
                ['form' => $form],
            );
        }

        $this->newsAdminService->save($item);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The news item was published.'),
        );

        return $this->redirectToRoute('admin/frontpage/news/index');
    }

    #[Route(
        path: '/{item}/edit',
        name: 'edit',
        requirements: ['item' => '\d+'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        NewsItem $item,
    ): Response {
        $form = $this->createForm(
            NewsItemType::class,
            $item,
        )->handleRequest($request);

        if (
            !$form->isSubmitted()
            || !$form->isValid()
        ) {
            return $this->render(
                'frontpage/admin/news/edit.html.twig',
                [
                    'form' => $form,
                    'item' => $item,
                ],
            );
        }

        $this->newsAdminService->save($item);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The news item was saved.'),
        );

        return $this->redirectToRoute('admin/frontpage/news/index');
    }

    #[Route(
        path: '/{item}/delete',
        name: 'delete',
        requirements: ['item' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"news_delete-" ~ args["item"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function delete(NewsItem $item): Response
    {
        $this->newsAdminService->delete($item);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The news item was removed.'),
        );

        return $this->redirectToRoute('admin/frontpage/news/index');
    }
}
