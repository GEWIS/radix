<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\Keyholder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Keyholder>
 */
class KeyholderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Keyholder::class,
        );
    }

    /**
     * @return Paginator<Keyholder>
     */
    public function paginateKeyholders(
        bool $includeExpired,
        bool $includeDeleted,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('k');
        $qb->innerJoin(
            'k.member',
            'm',
        )
            ->addSelect('m');

        if (!$includeExpired) {
            $qb->andWhere('k.expirationDate >= CURRENT_DATE()')
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('k.withdrawnDate'),
                    $qb->expr()->gte(
                        'k.withdrawnDate',
                        'CURRENT_DATE()',
                    ),
                ));
        }

        if (!$includeDeleted) {
            $qb->andWhere('m.deleted = false');
        }

        $qb->orderBy(
            'm.lidnr',
            'ASC',
        )
            ->addOrderBy(
                'k.id',
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
