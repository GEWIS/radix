<?php

declare(strict_types=1);

namespace App\Controller\Activity;

use App\Attribute\User\Replayable;
use App\Controller\Application\AbstractLabelController;
use App\Entity\Activity\ActivityLabel;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Activity\ActivityLabelRepository;
use App\Repository\Application\LabelRepositoryInterface;
use Override;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(
    attribute: UserRoles::Board->value,
    message: 'You are not allowed to administer activities.',
)]
#[Route(
    path: '/admin/activities/labels',
    name: 'admin/activities/labels/',
)]
class AdminActivityLabelController extends AbstractLabelController
{
    public function __construct(private readonly ActivityLabelRepository $labelRepository)
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
        ActivityLabel $label,
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
        id: new Expression('"activity_label_delete-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function delete(ActivityLabel $label): Response
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
        id: new Expression('"activity_label_retire-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function retire(ActivityLabel $label): Response
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
        id: new Expression('"activity_label_restore-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function restore(ActivityLabel $label): Response
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
        return ActivityLabel::class;
    }

    /**
     * Every case rather than the selectable ones, because a label named after the category a legacy activity was
     * migrated into reads as that category all the same.
     */
    #[Override]
    protected function categories(): array
    {
        return ActivityCategories::cases();
    }

    #[Override]
    protected function indexRoute(): string
    {
        return 'admin/activities/labels/index';
    }

    #[Override]
    protected function indexTemplate(): string
    {
        return 'activity/admin/labels/index.html.twig';
    }

    #[Override]
    protected function editTemplate(): string
    {
        return 'activity/admin/labels/edit.html.twig';
    }
}
