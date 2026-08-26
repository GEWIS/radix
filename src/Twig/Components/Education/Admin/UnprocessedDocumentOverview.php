<?php

declare(strict_types=1);

namespace App\Twig\Components\Education\Admin;

use App\Entity\Education\CourseDocument;
use App\Entity\User\Enums\UserRoles;
use App\Repository\Education\CourseDocumentRepository;
use App\Twig\Components\Application\AbstractDoctrinePaginatedOverview;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Override;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;

/**
 * The course documents that are not downloadable yet, worst status first, paged through. Renders nothing at all when
 * the queue is empty, so the page below the tiles is quiet on a day where there is nothing to look at.
 *
 * @extends AbstractDoctrinePaginatedOverview<CourseDocument>
 */
#[AsLiveComponent(
    name: 'Education:Admin:UnprocessedDocumentOverview',
    template: 'components/Education/Admin/UnprocessedDocumentOverview.html.twig',
)]
#[IsGranted(UserRoles::Board->value)]
final class UnprocessedDocumentOverview extends AbstractDoctrinePaginatedOverview
{
    public function __construct(private readonly CourseDocumentRepository $documentRepository)
    {
    }

    /**
     * @return list<CourseDocument>
     */
    public function getDocuments(): array
    {
        return $this->getRows();
    }

    /**
     * @return Paginator<CourseDocument>
     */
    #[Override]
    protected function createPaginator(
        int $page,
        int $pageSize,
    ): Paginator {
        return $this->documentRepository->paginateNotReady(
            $page,
            $pageSize,
        );
    }
}
