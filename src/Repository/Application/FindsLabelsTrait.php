<?php

declare(strict_types=1);

namespace App\Repository\Application;

use App\Entity\Application\LabelInterface;
use SortDirection;

trait FindsLabelsTrait
{
    /**
     * @param int[] $andIds
     *
     * @return list<LabelInterface>
     */
    public function findActiveWithName(array $andIds = []): array
    {
        $qb = $this->createQueryBuilder('l')
            ->select(
                'l',
                'n',
            )
            ->leftJoin(
                'l.name',
                'n',
            )
            ->where('l.retired = false')
            ->orderBy(
                'l.id',
                SortDirection::Ascending,
            );

        if ([] !== $andIds) {
            $qb->orWhere('l.id IN (:ids)')
                ->setParameter(
                    'ids',
                    $andIds,
                );
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Counted in the query: the overview only shows the number, and reading it from each label's collection would load
     * every revision of every label.
     *
     * @return list<array{label: LabelInterface, usage: int}>
     */
    public function findAllWithUsage(): array
    {
        return $this->createQueryBuilder('l')
            ->select(
                'l AS label',
                'n',
                'COUNT(r.id) AS usage',
            )
            ->leftJoin(
                'l.name',
                'n',
            )
            ->leftJoin(
                'l.revisions',
                'r',
            )
            ->groupBy('l.id')
            ->addGroupBy('n.id')
            ->orderBy(
                'l.id',
                SortDirection::Ascending,
            )
            ->getQuery()
            ->getResult();
    }
}
