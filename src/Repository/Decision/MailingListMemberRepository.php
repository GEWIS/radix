<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\MailingListMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailingListMember>
 */
class MailingListMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            MailingListMember::class,
        );
    }

    /**
     * @return Paginator<MailingListMember>
     */
    public function paginateSubscribers(
        string $mailingList,
        bool $includeDeleted,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('mlm');

        $qb->addSelect('m')
            ->innerJoin(
                'mlm.member',
                'm',
            )
            ->where('mlm.mailingList = :mailingList')
            ->setParameter(
                'mailingList',
                $mailingList,
                Types::STRING,
            );

        if (!$includeDeleted) {
            $qb->andWhere('m.deleted = false');
        }

        $qb->orderBy(
            'm.lidnr',
            'ASC',
        )
            ->addOrderBy(
                'mlm.email',
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
