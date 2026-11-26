<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Organ;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

use function array_map;
use function mb_strtolower;
use function trim;

/**
 * @extends ServiceEntityRepository<Organ>
 */
class OrganRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Organ::class,
        );
    }

    /**
     * Find all active organs.
     *
     * @return Organ[]
     */
    public function findActive(?OrganTypes $type = null): array
    {
        // `organInformation` is an inverse-side one-to-one, which Doctrine cannot proxy: without the join every
        // organ costs a SELECT of its own, whether or not the caller looks at the page.
        $qb = $this->createQueryBuilder('o')
            ->leftJoin(
                'o.organInformation',
                'oi',
            )
            ->addSelect('oi');
        $qb->where($qb->expr()->orX(
            $qb->expr()->isNull('o.abrogationDate'),
            $qb->expr()->gt(
                'o.abrogationDate',
                ':now',
            ),
        ))
            ->setParameter(
                'now',
                new DateTimeImmutable(),
                Types::DATETIME_IMMUTABLE,
            );

        if (null !== $type) {
            $qb->andWhere('o.type = :type')
                ->setParameter(
                    'type',
                    $type,
                );
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Paginator<Organ>
     */
    public function paginateBodies(
        ?OrganTypes $type,
        bool $includeAbrogated,
        int $page,
        int $pageSize,
    ): Paginator {
        // `organInformation` is an inverse-side one-to-one, which Doctrine cannot proxy: without the join the
        // listing costs one SELECT per row on the page.
        $qb = $this->createQueryBuilder('o')
            ->leftJoin(
                'o.organInformation',
                'oi',
            )
            ->addSelect('oi');

        if (!$includeAbrogated) {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('o.abrogationDate'),
                $qb->expr()->gt(
                    'o.abrogationDate',
                    ':now',
                ),
            ))
                ->setParameter(
                    'now',
                    new DateTimeImmutable(),
                    Types::DATETIME_IMMUTABLE,
                );
        }

        if (null !== $type) {
            $qb->andWhere('o.type = :type')
                ->setParameter(
                    'type',
                    $type,
                );
        }

        $qb->orderBy(
            'o.abbr',
            SortDirection::Ascending,
        )
            ->addOrderBy(
                'o.id',
                SortDirection::Ascending,
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        return new Paginator(
            $qb->getQuery(),
            false,
        );
    }

    /**
     * The ids of the bodies of one kind that an overview lists, in alphabetical order. Ids rather than entities so the
     * overview can shuffle or page them before hydrating only the ones it shows. When labels are given, only the
     * bodies whose live revision carries one of them, because that is the revision the overviews show on the card.
     *
     * @param int[] $labelIds
     *
     * @return int[]
     */
    public function findOverviewIds(
        OrganTypes $type,
        bool $abrogated,
        string $search = '',
        array $labelIds = [],
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.type = :type')
            ->setParameter(
                'type',
                $type,
            )
            ->orderBy(
                'o.abbr',
                SortDirection::Ascending,
            );

        $now = new DateTimeImmutable();
        if ($abrogated) {
            $qb->andWhere('o.abrogationDate IS NOT NULL')
                ->andWhere('o.abrogationDate <= :now')
                ->setParameter(
                    'now',
                    $now,
                    Types::DATETIME_IMMUTABLE,
                );
        } else {
            $qb->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('o.abrogationDate'),
                $qb->expr()->gt(
                    'o.abrogationDate',
                    ':now',
                ),
            ))
                ->setParameter(
                    'now',
                    $now,
                    Types::DATETIME_IMMUTABLE,
                );
        }

        $search = trim($search);
        if ('' !== $search) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(UNACCENT(o.abbr)) LIKE LOWER(UNACCENT(:search))',
                'LOWER(UNACCENT(o.name)) LIKE LOWER(UNACCENT(:search))',
            ))
                ->setParameter(
                    'search',
                    '%' . mb_strtolower($search) . '%',
                );
        }

        // A subquery rather than a join, because a body with two of the labels would come back twice and the ids
        // feed both the count and the ordered list.
        if ([] !== $labelIds) {
            $withLabels = $this->createQueryBuilder('f')
                ->select('f.id')
                ->join(
                    'f.organInformation',
                    'fi',
                )
                ->join(
                    'fi.liveRevision',
                    'flr',
                )
                ->join(
                    'flr.labels',
                    'flb',
                )
                ->where('flb.id IN (:labelIds)');

            // The subquery's parameters stay on its own builder, and the DQL is embedded as text, so the binding has
            // to be made on the outer query or the placeholder goes unbound.
            $qb->andWhere($qb->expr()->in('o.id', $withLabels->getDQL()))
                ->setParameter(
                    'labelIds',
                    $labelIds,
                );
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $qb->getQuery()->getScalarResult(),
        );
    }

    /**
     * Hydrate the given bodies, in the order they are requested, with the page each one is shown by. The labels are
     * fetched with it: the card draws a badge per label, which is two lazy loads per card (the collection, then a
     * name row per label) without the fetch. Ids that no longer resolve to a body are left out.
     *
     * @param int[] $ids
     *
     * @return Organ[]
     */
    public function findOverviewByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $organs = $this->createQueryBuilder(
            'o',
            'o.id',
        )
            ->addSelect(
                'i',
                'lr',
                'sd',
                'sl',
                'lbl',
                'lbln',
            )
            ->leftJoin(
                'o.organInformation',
                'i',
            )
            ->leftJoin(
                'i.liveRevision',
                'lr',
            )
            ->leftJoin(
                'lr.shortDescription',
                'sd',
            )
            ->leftJoin(
                'lr.socialLinks',
                'sl',
            )
            ->leftJoin(
                'lr.labels',
                'lbl',
            )
            ->leftJoin(
                'lbl.name',
                'lbln',
            )
            ->where('o.id IN (:ids)')
            ->setParameter(
                'ids',
                $ids,
            )
            ->getQuery()
            ->getResult();

        $ordered = [];
        foreach ($ids as $id) {
            if (!isset($organs[$id])) {
                continue;
            }

            $ordered[] = $organs[$id];
        }

        return $ordered;
    }

    /**
     * Every body that has gone by this abbreviation, newest first. An abbreviation is reused: a committee is
     * abrogated and years later another one is founded under the same letters, and both have a page of their own.
     *
     * @return Organ[]
     */
    public function findAllByAbbr(
        string $abbr,
        ?OrganTypes $type = null,
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->where('o.abbr = :abbr')
            ->setParameter(
                'abbr',
                $abbr,
            )
            ->orderBy(
                'o.foundationDate',
                SortDirection::Descending,
            );

        if (null !== $type) {
            $qb->andWhere('o.type = :type')
                ->setParameter(
                    'type',
                    $type,
                );
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Eagerly loads what the administrative overview reads from each body: its page, the revision being worked on, the
     * one before that and the one visitors see. Without this every row lazy-loads four associations of its own, which
     * for the board, who sees every body there is, runs into the hundreds of queries.
     *
     * The result is discarded; hydrating it fills the associations on the bodies that were passed in.
     *
     * @param Organ[] $organs
     */
    public function warmPageAssociations(array $organs): void
    {
        if ([] === $organs) {
            return;
        }

        $this->createQueryBuilder('o')
            ->addSelect(
                'i',
                'current',
                'previous',
                'live',
            )
            ->leftJoin(
                'o.organInformation',
                'i',
            )
            ->leftJoin(
                'i.currentRevision',
                'current',
            )
            ->leftJoin(
                'current.previousRevision',
                'previous',
            )
            ->leftJoin(
                'i.liveRevision',
                'live',
            )
            ->where('o IN (:organs)')
            ->setParameter(
                'organs',
                $organs,
            )
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if an organ with id `$id` is not abrogated.
     */
    public function findActiveById(int $id): ?Organ
    {
        $qb = $this->createQueryBuilder('o');
        $qb->where('o.id = :id')
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('o.abrogationDate'),
                $qb->expr()->gt(
                    'o.abrogationDate',
                    ':now',
                ),
            ));

        $qb->setParameter(
            'id',
            $id,
        )
            ->setParameter(
                'now',
                new DateTimeImmutable(),
                Types::DATETIME_IMMUTABLE,
            );

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find all abrogated organs.
     *
     * @return Organ[]
     */
    public function findAbrogated(?OrganTypes $type = null): array
    {
        // `organInformation` is an inverse-side one-to-one, which Doctrine cannot proxy: without the join every
        // organ costs a SELECT of its own, whether or not the caller looks at the page.
        $qb = $this->createQueryBuilder('o')
            ->leftJoin(
                'o.organInformation',
                'oi',
            )
            ->addSelect('oi');
        $qb->where($qb->expr()->andX(
            $qb->expr()->isNotNull('o.abrogationDate'),
            $qb->expr()->lte(
                'o.abrogationDate',
                ':now',
            ),
        ))
            ->setParameter(
                'now',
                new DateTimeImmutable(),
                Types::DATETIME_IMMUTABLE,
            )
            ->orderBy(
                'o.abrogationDate',
                SortDirection::Descending,
            );

        if (null !== $type) {
            $qb->andWhere('o.type = :type')
                ->setParameter(
                    'type',
                    $type,
                );
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find an organ with all information.
     *
     * @throws NonUniqueResultException
     */
    public function findOrgan(int $id): ?Organ
    {
        $qb = $this->createQueryBuilder('o');
        $qb->addSelect('om, m')
            ->leftJoin(
                'o.members',
                'om',
            )
            ->leftJoin(
                'om.member',
                'm',
            )
            ->where('o.id = :id');

        $qb->setParameter(
            'id',
            $id,
        );

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find an organ by its abbreviation.
     *
     * It is possible that multiple organs with the same abbreviation exist,
     * for example, through the reinstatement of a previously abrogated organ.
     * To retrieve the latest occurrence of such an organ use `$latest`.
     *
     * @param bool $latest Whether to retrieve the latest occurrence of an organ or not
     *
     * @throws NoResultException
     * @throws NonUniqueResultException
     */
    public function findByAbbr(
        string $abbr,
        bool $latest,
        ?OrganTypes $type = null,
    ): ?Organ {
        $qb = $this->createQueryBuilder('o');
        $qb->select('o, om, m')
            ->leftJoin(
                'o.members',
                'om',
            )
            ->leftJoin(
                'om.member',
                'm',
            )
            ->where('o.abbr = :abbr')
            ->setParameter(
                'abbr',
                $abbr,
            );

        if (null !== $type) {
            $qb->andWhere('o.type = :type')
                ->setParameter(
                    'type',
                    $type,
                );
        }

        if ($latest) {
            $qb->orderBy(
                'o.foundationDate',
                SortDirection::Descending,
            );
            $queryResult = $qb->getQuery()->getResult();

            if ([] === $queryResult) {
                // the query did not return any records
                return null;
            }

            // the query returned at least 1 record, use first (= latest) record
            return $queryResult[0];
        }

        return $qb->getQuery()->getSingleResult();
    }
}
