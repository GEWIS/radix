<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\BoardMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BoardMember>
 */
class BoardMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            BoardMember::class,
        );
    }

    /**
     * @return Paginator<BoardMember>
     */
    public function paginateBoardMembers(
        bool $includeFormer,
        bool $includeDeleted,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('bm');
        $qb->innerJoin(
            'bm.member',
            'm',
        )
            ->addSelect('m');

        if (!$includeFormer) {
            $qb->andWhere('bm.installDate <= CURRENT_TIMESTAMP()')
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('bm.releaseDate'),
                    $qb->expr()->gt(
                        'bm.releaseDate',
                        'CURRENT_TIMESTAMP()',
                    ),
                ))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('bm.dischargeDate'),
                    $qb->expr()->gt(
                        'bm.dischargeDate',
                        'CURRENT_TIMESTAMP()',
                    ),
                ));
        }

        if (!$includeDeleted) {
            $qb->andWhere('m.deleted = false');
        }

        $qb->orderBy(
            'bm.installDate',
            'DESC',
        )
            ->addOrderBy(
                'm.lidnr',
                'ASC',
            )
            ->addOrderBy(
                'bm.id',
                'ASC',
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator(
            $qb->getQuery(),
            false,
        );
    }
}
