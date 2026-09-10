<?php

declare(strict_types=1);

namespace App\Repository\Activity;

use App\Entity\Activity\ActivityLabel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<ActivityLabel>
 */
class ActivityLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            ActivityLabel::class,
        );
    }

    /**
     * Every label with its localised name fetch-joined, so rendering the label checkboxes on the activity form does not
     * lazy-load one name per label.
     *
     * @return ActivityLabel[]
     */
    public function findAllWithName(): array
    {
        return $this->createQueryBuilder('l')
            ->select(
                'l',
                'n',
            )
            ->leftJoin(
                'l.name',
                'n',
            )
            ->orderBy(
                'l.id',
                SortDirection::Ascending,
            )
            ->getQuery()
            ->getResult();
    }
}
