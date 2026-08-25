<?php

declare(strict_types=1);

namespace App\Repository\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Repository\Application\FindsRevisionsForReviewTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActivityRevision>
 */
class ActivityRevisionRepository extends ServiceEntityRepository
{
    use FindsRevisionsForReviewTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            ActivityRevision::class,
        );
    }

    /**
     * The revisions awaiting board attention (submitted, or already being reviewed), oldest first.
     *
     * @return ActivityRevision[]
     */
    public function findForReview(): array
    {
        // The queue says who put each one forward and what is live while it waits, so both come along with it.
        $builder = $this->createQueryBuilder('r')
            ->addSelect(
                'n',
                'a',
                'au',
                'lr',
            )
            ->join(
                'r.name',
                'n',
            )
            ->join(
                'r.activity',
                'a',
            )
            ->leftJoin(
                'r.author',
                'au',
            )
            ->leftJoin(
                'a.liveRevision',
                'lr',
            );

        $this->whereAwaitingReview($builder);
        $this->orderOldestFirst($builder);

        return $builder->getQuery()
            ->getResult();
    }

    /**
     * The same queue, one page at a time: what the approvals page lists. The organ and company columns it shows come
     * from the revision, so those are fetched with it.
     *
     * @return Paginator<ActivityRevision>
     */
    public function paginateForReview(
        int $page,
        int $pageSize,
    ): Paginator {
        $builder = $this->createQueryBuilder('r')
            ->addSelect(
                'n',
                'a',
                'au',
                'lr',
                'o',
                'c',
            )
            ->join(
                'r.name',
                'n',
            )
            ->join(
                'r.activity',
                'a',
            )
            ->leftJoin(
                'r.author',
                'au',
            )
            ->leftJoin(
                'a.liveRevision',
                'lr',
            )
            ->leftJoin(
                'r.organ',
                'o',
            )
            ->leftJoin(
                'r.company',
                'c',
            );

        $this->whereAwaitingReview($builder);
        $this->orderOldestFirst($builder);

        $paginator = new Paginator(
            $builder,
            false,
        );
        $paginator->getQuery()
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return $paginator;
    }
}
