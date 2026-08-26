<?php

declare(strict_types=1);

namespace App\Repository\Checker;

use App\Entity\Database\Meeting;
use App\Entity\Database\SubDecision\Financial\Budget;
use App\Entity\Database\SubDecision\OrganRegulation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Queries over the documents a meeting approved: budgets, financial statements and body regulations.
 *
 * Neither of the two owns the other, so this is a query service rather than a repository bound to one of them. A
 * financial statement is a Budget as far as the mapping is concerned, so asking for one asks for both, which is what
 * the checks over them want anyway.
 */
class DocumentRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnnulledSubDecisionFilter $filter,
    ) {
    }

    /**
     * Returns all the budgets and financial statements decided on in a meeting.
     *
     * @return Budget[]
     */
    public function findBudgetsDuringMeeting(Meeting $meeting): array
    {
        $qb = $this->em->getRepository(Budget::class)->createQueryBuilder('s');

        /** @var Budget[] $result */
        $result = $this->duringMeeting(
            $qb,
            $meeting,
        )->getQuery()->getResult();

        return $this->filter->filterDeleted($result);
    }

    /**
     * Returns all the body regulations decided on in a meeting.
     *
     * @return OrganRegulation[]
     */
    public function findOrganRegulationsDuringMeeting(Meeting $meeting): array
    {
        $qb = $this->em->getRepository(OrganRegulation::class)->createQueryBuilder('s');

        /** @var OrganRegulation[] $result */
        $result = $this->duringMeeting(
            $qb,
            $meeting,
        )->getQuery()->getResult();

        return $this->filter->filterDeleted($result);
    }

    private function duringMeeting(
        QueryBuilder $qb,
        Meeting $meeting,
    ): QueryBuilder {
        return $qb->innerJoin(
            's.decision',
            'd',
        )
            ->innerJoin(
                'd.meeting',
                'm',
            )
            ->where('m.number = :meeting_number')
            ->andWhere('m.type = :meeting_type')
            ->setParameter(
                'meeting_number',
                $meeting->getNumber(),
            )
            ->setParameter(
                'meeting_type',
                $meeting->getType(),
            );
    }
}
