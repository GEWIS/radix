<?php

declare(strict_types=1);

namespace App\Repository\Database;

use App\Entity\Database\MailingList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Override;

use function mb_strtolower;
use function trim;

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
     * Persist a list.
     */
    public function persist(MailingList $list): void
    {
        $this->getEntityManager()->persist($list);
        $this->getEntityManager()->flush();
    }

    /**
     * Remove a list.
     */
    public function remove(MailingList $list): void
    {
        $this->getEntityManager()->remove($list);
        $this->getEntityManager()->flush();
    }

    /**
     * Find all.
     *
     * @return list<MailingList>
     */
    #[Override]
    public function findAll(): array
    {
        return $this->findBy(
            [],
            ['name' => 'ASC'],
        );
    }

    /**
     * One page of the mailing lists, optionally narrowed to a name or a description in either language.
     *
     * @return Paginator<MailingList>
     */
    public function paginateForOverview(
        string $search,
        int $page,
        int $pageSize,
    ): Paginator {
        $qb = $this->createQueryBuilder('l')
            ->orderBy(
                'l.name',
                'ASC',
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        $search = trim($search);
        if ('' !== $search) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(l.name) LIKE :search',
                'LOWER(l.nl_description) LIKE :search',
                'LOWER(l.en_description) LIKE :search',
            ))
                ->setParameter(
                    'search',
                    '%' . mb_strtolower($search) . '%',
                );
        }

        return new Paginator($qb);
    }

    /**
     * Find all mailing lists that are on the subscription form.
     *
     * @return array<array-key, MailingList>
     */
    public function findAllOnForm(): array
    {
        return $this->findBy(
            ['onForm' => true],
            ['name' => 'ASC'],
        );
    }

    /**
     * Find all default
     *
     * @return array<array-key, MailingList>
     */
    public function findDefault(): array
    {
        return $this->findBy(
            [
                'defaultSub' => true,
                'onForm' => false,
            ],
            [
                'name' => 'ASC',
            ],
        );
    }
}
