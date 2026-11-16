<?php

declare(strict_types=1);

namespace App\Controller\Decision;

use App\Attribute\User\Replayable;
use App\Controller\Application\AbstractLabelController;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\OrganLabel;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Application\LabelRepositoryInterface;
use App\Repository\Decision\OrganLabelRepository;
use Override;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The labels a body's page can carry, as seen on the committee page. The board decides the set; a body picks the
 * labels for its own page, and the board sees the choice when it reviews the page.
 */
#[IsGranted(
    attribute: UserRoles::Board->value,
    message: 'You are not allowed to administer bodies.',
)]
#[Route(
    path: '/admin/bodies/labels',
    name: 'admin/bodies/labels/',
)]
class AdminOrganLabelController extends AbstractLabelController
{
    public function __construct(private readonly OrganLabelRepository $labelRepository)
    {
    }

    #[Replayable]
    #[Route(
        path: '',
        name: 'index',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function index(Request $request): Response
    {
        return $this->handleIndex($request);
    }

    #[Replayable]
    #[Route(
        path: '/{label}/edit',
        name: 'edit',
        requirements: ['label' => '\d+'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        OrganLabel $label,
    ): Response {
        return $this->handleEdit(
            $request,
            $label,
        );
    }

    #[Route(
        path: '/{label}/delete',
        name: 'delete',
        requirements: ['label' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"body_label_delete-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function delete(OrganLabel $label): Response
    {
        return $this->handleDelete($label);
    }

    #[Route(
        path: '/{label}/retire',
        name: 'retire',
        requirements: ['label' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"body_label_retire-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function retire(OrganLabel $label): Response
    {
        return $this->handleRetire($label);
    }

    #[Route(
        path: '/{label}/restore',
        name: 'restore',
        requirements: ['label' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"body_label_restore-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function restore(OrganLabel $label): Response
    {
        return $this->handleRestore($label);
    }

    #[Override]
    protected function labelRepository(): LabelRepositoryInterface
    {
        return $this->labelRepository;
    }

    #[Override]
    protected function labelClass(): string
    {
        return OrganLabel::class;
    }

    /**
     * Every kind of body rather than the committees alone, because a label named after a kind of body reads as that
     * kind all the same.
     */
    #[Override]
    protected function categories(): array
    {
        return OrganTypes::cases();
    }

    #[Override]
    protected function indexRoute(): string
    {
        return 'admin/bodies/labels/index';
    }

    #[Override]
    protected function indexTemplate(): string
    {
        return 'decision/admin/bodies/labels/index.html.twig';
    }

    #[Override]
    protected function editTemplate(): string
    {
        return 'decision/admin/bodies/labels/edit.html.twig';
    }
}
