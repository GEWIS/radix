<?php

declare(strict_types=1);

namespace App\Repository\Activity;

use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\Enums\RevisionStatus;
use App\Repository\Application\FindsRevisionsForReviewTrait;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

use function array_map;

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

    /**
     * Revisions in one of the given states that are still the working head of their activity and have not been touched
     * since the cutoff, oldest first. Approved heads are never eligible whatever is asked for: the live version of an
     * activity is not abandoned, it is finished.
     *
     * @return ActivityRevision[]
     */
    public function findStaleHeads(
        DateTime $cutoff,
        RevisionStatus ...$statuses,
    ): array {
        return $this->createQueryBuilder('r')
            ->join(
                'r.activity',
                'a',
            )
            ->where('r.status IN (:statuses)')
            ->andWhere('r.status <> :approved')
            ->andWhere('r.updatedAt <= :cutoff')
            ->andWhere('a.currentRevision = r')
            ->setParameter(
                'statuses',
                array_map(
                    static fn (RevisionStatus $status): string => $status->value,
                    $statuses,
                ),
            )
            ->setParameter(
                'approved',
                RevisionStatus::Approved->value,
            )
            ->setParameter(
                'cutoff',
                $cutoff,
                Types::DATETIME_MUTABLE,
            )
            ->orderBy(
                'r.updatedAt',
                'ASC',
            )
            ->getQuery()
            ->getResult();
    }
}
