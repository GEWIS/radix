<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\OrganMember;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganMember>
 */
class OrganMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            OrganMember::class,
        );
    }

    /**
     * @return Paginator<OrganMember>
     */
    public function paginateByBody(
        int $body,
        bool $includeDischarged,
        bool $includeDeleted,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('om');

        $qb->addSelect('m')
            ->innerJoin(
                'om.member',
                'm',
            )
            ->where('om.organ = :body')
            ->setParameter(
                'body',
                $body,
            );

        if (!$includeDischarged) {
            $this->onlyCurrent($qb);
        }

        if (!$includeDeleted) {
            $qb->andWhere('m.deleted = false');
        }

        $qb->orderBy(
            'm.lidnr',
            'ASC',
        )
            ->addOrderBy(
                'om.id',
                'ASC',
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator(
            $qb->getQuery(),
            false,
        );
    }

    /**
     * @return Paginator<OrganMember>
     */
    public function paginateByMember(
        int $lidnr,
        bool $includeDischarged,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('om');

        // `organInformation` is an inverse-side one-to-one, which Doctrine cannot proxy: without the join each
        // distinct body on the page costs one extra SELECT.
        $qb->addSelect('o')
            ->innerJoin(
                'om.organ',
                'o',
            )
            ->addSelect('oi')
            ->leftJoin(
                'o.organInformation',
                'oi',
            )
            ->where('om.member = :lidnr')
            ->setParameter(
                'lidnr',
                $lidnr,
            );

        if (!$includeDischarged) {
            $this->onlyCurrent($qb);
        }

        $qb->orderBy(
            'o.abbr',
            'ASC',
        )
            ->addOrderBy(
                'om.id',
                'ASC',
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator(
            $qb->getQuery(),
            false,
        );
    }

    private function onlyCurrent(QueryBuilder $qb): void
    {
        $qb->andWhere('om.installDate <= :now')
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('om.dischargeDate'),
                $qb->expr()->gte(
                    'om.dischargeDate',
                    ':now',
                ),
            ))
            ->setParameter(
                'now',
                new DateTime(),
                Types::DATETIME_MUTABLE,
            );
    }
}
