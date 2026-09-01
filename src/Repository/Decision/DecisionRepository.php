<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Decision\Decision;
use App\Service\Decision\DecisionSearchQuery;
use App\Service\Decision\MeetingReference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function addcslashes;
use function assert;
use function implode;
use function sprintf;

/**
 * @extends ServiceEntityRepository<Decision>
 */
class DecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Decision::class,
        );
    }

    /**
     * Search decisions: every included term must appear in the Dutch or English text, no excluded term may, and the
     * `type:` and `meeting:` filters narrow the text matches to one meeting type and one meeting. Alongside the text
     * match, a meeting the prompt spells out ("BV 123.4.5") matches those decisions directly, and they are answered
     * with first: the meeting asked for must not be pushed past the result cap by the decisions that mention it.
     *
     * @return Decision[]
     */
    public function search(DecisionSearchQuery $search): array
    {
        if ($search->isEmpty()) {
            return [];
        }

        $qb = $this->createQueryBuilder('d');
        // `annulledBy` is an inverse-side one-to-one, which Doctrine cannot proxy: without the join a full page of
        // results costs a hundred extra queries, one per decision, for something most of them do not have.
        $qb->addSelect('m, meetingMinutes, localDetails, decisionMinutes, annulledBy')
            ->join(
                'd.meeting',
                'm',
            )
            ->leftJoin(
                'd.annulledBy',
                'annulledBy',
            )
            // Joined rather than asked for with `d.counterpart IS NULL`: the association is keyed on the decision's
            // four columns, and DQL refuses a single-valued path expression to a composite key.
            ->leftJoin(
                'd.counterpart',
                'counterpart',
            )
            ->leftJoin(
                'm.meetingMinutes',
                'meetingMinutes',
            )
            ->leftJoin(
                'm.localDetails',
                'localDetails',
            )
            ->leftJoin(
                'm.minutes',
                'decisionMinutes',
            )
            ->setMaxResults(100);

        $conditions = [];

        $textParts = [];
        foreach ($search->includeTerms as $index => $term) {
            $textParts[] = sprintf(
                '(d.contentNL LIKE :include%1$d OR d.contentEN LIKE :include%1$d)',
                $index,
            );
            $qb->setParameter(
                'include' . $index,
                '%' . addcslashes(
                    $term,
                    '%_',
                ) . '%',
            );
        }

        foreach ($search->excludeTerms as $index => $term) {
            $textParts[] = sprintf(
                '(d.contentNL NOT LIKE :exclude%1$d AND d.contentEN NOT LIKE :exclude%1$d)',
                $index,
            );
            $qb->setParameter(
                'exclude' . $index,
                '%' . addcslashes(
                    $term,
                    '%_',
                ) . '%',
            );
        }

        if (null !== $search->type) {
            $textParts[] = 'm.type = :searchType';
            $qb->setParameter(
                'searchType',
                $search->type->value,
            );
        }

        if (null !== $search->meeting) {
            $textParts[] = $this->referenceCondition(
                $qb,
                $search->meeting,
                'filter',
            );
        }

        if ([] !== $textParts) {
            if (null === $search->meeting) {
                // A virtual decision that names the decision it belongs to is that decision said a second time, and
                // showing both is what made the same organ membership turn up twice. The one taken in a real meeting
                // is the one that answers, so the other is left out of the text match. Added inside this branch,
                // because on its own it would match every decision there is; a prompt that asks for a meeting, either
                // by naming it or by filtering on it, is asking for what that meeting decided and gets it.
                $textParts[] = 'counterpart.number IS NULL';
            }

            $conditions[] = '(' . implode(
                ' AND ',
                $textParts,
            ) . ')';
        }

        if (null !== $search->reference) {
            $reference = $this->referenceCondition(
                $qb,
                $search->reference,
                'reference',
            );
            $conditions[] = $reference;

            // The decisions asked for, ahead of the ones that only mention them. Ordering by the date alone leaves
            // the meeting itself last, where the cap can cut it off entirely: "BV 1" answers with a century of
            // decisions that happen to contain a 1 before it reaches the meeting.
            $qb->addSelect(sprintf(
                'CASE WHEN %s THEN 0 ELSE 1 END AS HIDDEN referenceRank',
                $reference,
            ))
                ->orderBy(
                    'referenceRank',
                    'ASC',
                )
                ->addOrderBy(
                    'm.date',
                    'DESC',
                );
        } else {
            $qb->orderBy(
                'm.date',
                'DESC',
            );
        }

        if ([] === $conditions) {
            return [];
        }

        $qb->where(implode(
            ' OR ',
            $conditions,
        ));

        return $qb->getQuery()->getResult();
    }

    /**
     * The virtual counterparts of any of the given decisions, keyed by the decision they belong to.
     *
     * A query of its own rather than a join on the search: the search caps its results, and a limit over a fetch-
     * joined collection truncates the wrong thing. One decision can be given more than one, so the values are lists.
     *
     * @param list<Decision> $decisions
     *
     * @return array<string, list<Decision>>
     */
    public function findVirtualCounterpartsOf(array $decisions): array
    {
        if ([] === $decisions) {
            return [];
        }

        $qb = $this->createQueryBuilder('r');
        $qb->addSelect('c')
            ->join(
                'r.counterpart',
                'c',
            );

        $clauses = [];
        foreach ($decisions as $index => $decision) {
            $clauses[] = sprintf(
                '(c.meeting_type = :type%1$d AND c.meeting_number = :meeting%1$d'
                . ' AND c.point = :point%1$d AND c.number = :number%1$d)',
                $index,
            );
            $qb->setParameter(
                'type' . $index,
                $decision->getMeetingType()->value,
            )
                ->setParameter(
                    'meeting' . $index,
                    $decision->getMeetingNumber(),
                )
                ->setParameter(
                    'point' . $index,
                    $decision->getPoint(),
                )
                ->setParameter(
                    'number' . $index,
                    $decision->getNumber(),
                );
        }

        $qb->where(implode(
            ' OR ',
            $clauses,
        ))
            ->orderBy(
                'r.meeting_number',
                'ASC',
            )
            ->addOrderBy(
                'r.point',
                'ASC',
            )
            ->addOrderBy(
                'r.number',
                'ASC',
            );

        $counterparts = [];
        foreach ($qb->getQuery()->getResult() as $virtual) {
            assert($virtual instanceof Decision);
            $counterpart = $virtual->getCounterpart();

            if (null === $counterpart) {
                continue;
            }

            $counterparts[self::key($counterpart)][] = $virtual;
        }

        return $counterparts;
    }

    /**
     * How a decision is addressed in the map {@see self::findVirtualCounterpartsOf()} answers with.
     */
    public static function key(Decision $decision): string
    {
        return sprintf(
            '%s %d.%d.%d',
            $decision->getMeetingType()->value,
            $decision->getMeetingNumber(),
            $decision->getPoint(),
            $decision->getNumber(),
        );
    }

    /**
     * The DQL matching one meeting reference, with its parameters bound under a prefix of their own so that the
     * filter and the spelled-out reference can both appear in the same query.
     */
    private function referenceCondition(
        QueryBuilder $qb,
        MeetingReference $reference,
        string $prefix,
    ): string {
        $parts = [];

        // Absent from a reference that gives a number without a type, which addresses every meeting numbered that.
        if (null !== $reference->type) {
            $parts[] = sprintf(
                'd.meeting_type = :%stype',
                $prefix,
            );
            $qb->setParameter(
                $prefix . 'type',
                $reference->type->value,
            );
        }

        $parts[] = sprintf(
            'd.meeting_number = :%snumber',
            $prefix,
        );
        $qb->setParameter(
            $prefix . 'number',
            $reference->number,
        );

        if (null !== $reference->point) {
            $parts[] = sprintf(
                'd.point = :%spoint',
                $prefix,
            );
            $qb->setParameter(
                $prefix . 'point',
                $reference->point,
            );
        }

        if (null !== $reference->decision) {
            $parts[] = sprintf(
                'd.number = :%sdecision',
                $prefix,
            );
            $qb->setParameter(
                $prefix . 'decision',
                $reference->decision,
            );
        }

        return '(' . implode(
            ' AND ',
            $parts,
        ) . ')';
    }
}
