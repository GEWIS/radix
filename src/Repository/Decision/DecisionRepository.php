<?php

declare(strict_types=1);

namespace App\Repository\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Decision;
use App\Service\Decision\DecisionSearchQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

use function addcslashes;
use function assert;
use function implode;
use function is_numeric;
use function preg_match;
use function sprintf;
use function strval;

use const PREG_UNMATCHED_AS_NULL;

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
     * Search decisions: every included term must appear in the Dutch or English text, no excluded term may, and an
     * optional meeting type narrows the text matches. Alongside the text match, the prompt is checked for a meeting
     * reference such as "BV 123.4.5", which matches those decisions directly.
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
            ->orderBy(
                'm.date',
                'DESC',
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

        if ([] !== $textParts) {
            // A virtual decision that names the decision it belongs to is that decision said a second time, and
            // showing both is what made the same organ membership turn up twice. The one taken in a real meeting is
            // the one that answers, so the other is left out of the text match. Added inside this branch, because on
            // its own it would match every decision there is; a prompt that names a virtual decision by its own
            // reference still finds it, through the condition below.
            $textParts[] = 'counterpart.number IS NULL';

            $conditions[] = '(' . implode(
                ' AND ',
                $textParts,
            ) . ')';
        }

        $reference = $this->referenceCondition(
            $qb,
            $search->remainder,
        );
        if (null !== $reference) {
            $conditions[] = $reference;
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
     * The DQL condition matching a meeting reference in the prompt, with its parameters bound; null when the prompt
     * contains none.
     */
    private function referenceCondition(
        QueryBuilder $qb,
        string $remainder,
    ): ?string {
        // Start by matching meeting type and meeting number, then we also match additional meeting points and decision
        // numbers. Both the Dutch and English abbreviation for the meeting types can be used.
        //
        // To make it usable, we also split the meeting type and meeting number match into two separate capture groups.
        // In total there are four capture groups.
        //
        // Example:
        // BV 123.456.789
        //
        // Result:
        // array(5) {
        //     [0]=> string(14) "BV 123.456.789"
        //     [1]=> string(2) "BV"
        //     [2]=> string(3) "123"
        //     [3]=> string(3) "456"
        //     [4]=> string(3) "789"
        // }
        $meetingRegex = '/(?:(' . implode(
            '|',
            MeetingTypes::getSearchableStrings(),
        ) . ')'
            . ' ([0-9]+))(?:.([0-9]+))?(?:.([0-9]+))?/';
        $meetingInfo = [];
        if (
            1 === preg_match(
                $meetingRegex,
                $remainder,
                $meetingInfo,
                PREG_UNMATCHED_AS_NULL,
            )
        ) {
            $meetingType = MeetingTypes::tryFromSearch(strval($meetingInfo[1]));
            $meetingNumber = (int) $meetingInfo[2];

            $where = 'd.meeting_type = :meeting_type AND d.meeting_number = :meeting_number';
            if (null !== $meetingInfo[3]) {
                $where .= ' AND d.point = :point';
                $qb->setParameter(
                    'point',
                    (int) $meetingInfo[3],
                );
            }

            if (null !== $meetingInfo[4]) {
                $where .= ' AND d.number = :number';
                $qb->setParameter(
                    'number',
                    (int) $meetingInfo[4],
                );
            }

            $qb->setParameter(
                'meeting_type',
                $meetingType->value,
            )
                ->setParameter(
                    'meeting_number',
                    $meetingNumber,
                );

            return '(' . $where . ')';
        }

        if (is_numeric($remainder)) {
            $qb->setParameter(
                'meeting_number',
                (int) $remainder,
            );

            return '(d.meeting_number = :meeting_number)';
        }

        return null;
    }
}
