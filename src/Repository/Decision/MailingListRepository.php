<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\MailingList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailingList>
 */
class MailingListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            MailingList::class,
        );
    }

    /**
     * @return Paginator<MailingList>
     */
    public function paginateLists(
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('ml');

        $qb->orderBy(
            'ml.name',
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
