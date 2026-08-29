<?php

declare(strict_types=1);

namespace App\Repository\Database\SubDecision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\SubDecision\Other;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Other>
 */
class OtherRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Other::class,
        );
    }

    /**
     * @return array{items: list<Other>, total: int}
     */
    public function paginateWithoutEnglish(
        int $page,
        int $pageSize,
    ): array {
        /** @var list<Other> $items */
        $items = $this->withoutEnglish()
            ->orderBy(
                'm.date',
                'DESC',
            )
            ->addOrderBy(
                'd.point',
                'ASC',
            )
            ->addOrderBy(
                'd.number',
                'ASC',
            )
            ->addOrderBy(
                'o.sequence',
                'ASC',
            )
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $this->countWithoutEnglish(),
        ];
    }

    public function countWithoutEnglish(): int
    {
        return (int) $this->withoutEnglish()
            ->select('COUNT(o.sequence)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Filtering on the missing text here is what keeps a translated decision out of reach of the form. */
    public function findWithoutEnglish(
        MeetingTypes $type,
        int $number,
        int $point,
        int $decision,
        int $sequence,
    ): ?Other {
        return $this->findOneBy([
            'meeting_type' => $type,
            'meeting_number' => $number,
            'decision_point' => $point,
            'decision_number' => $decision,
            'sequence' => $sequence,
            'contentEN' => null,
        ]);
    }

    public function persist(Other $other): void
    {
        $this->getEntityManager()->persist($other);
        $this->getEntityManager()->flush();
    }

    private function withoutEnglish(): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->innerJoin(
                'o.decision',
                'd',
            )
            ->innerJoin(
                'd.meeting',
                'm',
            )
            ->where('o.contentEN IS NULL');
    }
}
