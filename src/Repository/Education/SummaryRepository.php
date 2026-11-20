<?php

declare(strict_types=1);

namespace App\Repository\Education;

use App\Entity\Decision\Member;
use App\Entity\Education\Summary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function addcslashes;

/**
 * @extends ServiceEntityRepository<Summary>
 */
class SummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Summary::class,
        );
    }

    /**
     * Get all summaries created by a specific member.
     *
     * @return Summary[]
     */
    public function findSummariesByAuthor(Member $member): array
    {
        $qb = $this->createQueryBuilder('d');
        $qb->where('LOWER(UNACCENT(d.author)) LIKE LOWER(UNACCENT(:full_name))')
            ->setParameter(
                'full_name',
                '%' . addcslashes(
                    $member->getFullName(),
                    '%_',
                ) . '%',
            );

        return $qb->getQuery()->getResult();
    }
}
