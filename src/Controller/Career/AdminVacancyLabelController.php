<?php

declare(strict_types=1);

namespace App\Controller\Career;

use App\Attribute\User\Replayable;
use App\Controller\Application\AbstractLabelController;
use App\Entity\Career\Enums\VacancyCategories;
use App\Entity\Career\VacancyLabel;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Application\LabelRepositoryInterface;
use App\Repository\Career\VacancyLabelRepository;
use Override;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_map;

#[IsGranted(
    attribute: UserRoles::CompanyAdmin->value,
    message: 'You are not allowed to administer companies.',
)]
#[Route(
    path: '/admin/career/vacancies/labels',
    name: 'admin/career/vacancies/labels/',
)]
class AdminVacancyLabelController extends AbstractLabelController
{
    public function __construct(private readonly VacancyLabelRepository $labelRepository)
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
        VacancyLabel $label,
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
        id: new Expression('"vacancy_label_delete-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function delete(VacancyLabel $label): Response
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
        id: new Expression('"vacancy_label_retire-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function retire(VacancyLabel $label): Response
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
        id: new Expression('"vacancy_label_restore-" ~ args["label"].id'),
        tokenKey: '_csrf_token',
    )]
    public function restore(VacancyLabel $label): Response
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
        return VacancyLabel::class;
    }

    /**
     * A vacancy category is named in the singular on a vacancy and in the plural in the menu, and a label may be
     * similar to neither.
     */
    #[Override]
    protected function categories(): array
    {
        return [
            ...VacancyCategories::cases(),
            ...array_map(
                static fn (VacancyCategories $category) => $category->pluralLabel(),
                VacancyCategories::cases(),
            ),
        ];
    }

    #[Override]
    protected function indexRoute(): string
    {
        return 'admin/career/vacancies/labels/index';
    }

    #[Override]
    protected function indexTemplate(): string
    {
        return 'career/admin/vacancies/labels/index.html.twig';
    }

    #[Override]
    protected function editTemplate(): string
    {
        return 'career/admin/vacancies/labels/edit.html.twig';
    }
}
