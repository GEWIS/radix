<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\OrganInformationRevision;
use App\Repository\Application\FindsRevisionsForReviewTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganInformationRevision>
 */
class OrganInformationRevisionRepository extends ServiceEntityRepository
{
    use FindsRevisionsForReviewTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            OrganInformationRevision::class,
        );
    }

    /**
     * The pages waiting on the board, oldest first. The queue names each one by its body and says what is live while
     * it waits, so the organ and that revision come along.
     *
     * @return OrganInformationRevision[]
     */
    public function findForReview(): array
    {
        $builder = $this->createQueryBuilder('r')
            ->addSelect(
                'i',
                'o',
                'a',
                'lr',
            )
            ->join(
                'r.organInformation',
                'i',
            )
            ->join(
                'i.organ',
                'o',
            )
            ->leftJoin(
                'r.author',
                'a',
            )
            ->leftJoin(
                'i.liveRevision',
                'lr',
            );

        $this->whereAwaitingReview($builder);
        $this->orderOldestFirst($builder);

        return $builder->getQuery()
            ->getResult();
    }
}
