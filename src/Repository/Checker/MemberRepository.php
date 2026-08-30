<?php

declare(strict_types=1);

namespace App\Repository\Checker;

use App\Entity\Application\AssociationYear;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\GraduateConversionLink;
use App\Entity\Database\Member;
use App\Entity\Database\Membership;
use App\Entity\Database\RenewalLink;
use App\Repository\Database\SubDecision\FoundationRepository;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Member>
 */
class MemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Member::class,
        );
    }

    /**
     * Get all expiring graduates for which no renewal link exists
     * The check for hidden is required because hidden members may also expire but should not be emailed
     *
     * @param ?DateTime $expiresBefore Latest expiry date, end of current association year if null
     *
     * @return Member[]
     */
    public function getExpiringGraduates(
        ?DateTime $expiresBefore = null,
        ?int $limit = null,
    ): array {
        $qb = $this->createQueryBuilder('m');

        $qb->select('m, mem')
            ->leftJoin(
                'm.memberships',
                'mem',
            )
            ->where('mem.type = :graduate')
            ->andWhere('m.email IS NOT NULL')
            ->andWhere('m.hidden = false')
            ->andWhere('m.deleted = false')
            ->andWhere($qb->expr()->eq('mem.startDate', '(' . $this->lastMembershipQuery()->getDQL() . ')'))
            ->andWhere('mem.endDate <= :expiresBefore')
            // Bounded at the far end, or the sweep works back through every graduate who ever expired.
            ->andWhere('mem.endDate >= :expiresAfter')
            ->setParameter(
                'expiresAfter',
                new DateTime()->modify('-' . RenewalLink::GRACE_DAYS . ' days')->setTime(
                    0,
                    0,
                ),
            )
            ->setParameter(
                'graduate',
                MembershipTypes::Graduate,
            );

        $qbal = $this->getEntityManager()->createQueryBuilder();
        $qbal->select('rl')
            ->from(
                RenewalLink::class,
                'rl',
            )
            ->andWhere('rl.member = m')
            ->andWhere('rl.currentExpiration = mem.endDate');

        $qb->setParameter(
            'expiresBefore',
            $expiresBefore ?? AssociationYear::fromDate(new DateTime())->endsOn(),
        );

        $qb->andWhere($qb->expr()->not(
            $qb->expr()->exists($qbal->getDQL()),
        ));

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * An active member's membership is renewed by the board rather than ended, so they are not asked.
     *
     * @param ?DateTime $expiresBefore Latest expiry date, end of current association year if null
     *
     * @return Member[]
     */
    public function getExpiringConversions(
        ?DateTime $expiresBefore = null,
        ?int $limit = null,
    ): array {
        $qb = $this->createQueryBuilder('m');

        $qb->select('m, mem')
            ->leftJoin(
                'm.memberships',
                'mem',
            )
            ->where($qb->expr()->in(
                'mem.type',
                ':convertible',
            ))
            ->andWhere('m.email IS NOT NULL')
            ->andWhere('m.hidden = false')
            ->andWhere('m.deleted = false')
            ->andWhere($qb->expr()->eq('mem.startDate', '(' . $this->lastMembershipQuery()->getDQL() . ')'))
            ->andWhere('mem.endDate <= :expiresBefore')
            // Bounded at both ends, or the first run works through every member who ever left.
            ->andWhere('mem.endDate >= :expiresAfter')
            ->setParameter(
                'expiresAfter',
                new DateTime()->modify('-' . GraduateConversionLink::GRACE_DAYS . ' days')->setTime(
                    0,
                    0,
                ),
            )
            ->setParameter(
                'convertible',
                [
                    MembershipTypes::Ordinary,
                    MembershipTypes::External,
                ],
            );

        $qbal = $this->getEntityManager()->createQueryBuilder();
        $qbal->select('gl')
            ->from(
                GraduateConversionLink::class,
                'gl',
            )
            ->andWhere('gl.member = m')
            ->andWhere('gl.currentExpiration = mem.endDate');

        $qb->setParameter(
            'expiresBefore',
            $expiresBefore ?? AssociationYear::fromDate(new DateTime())->endsOn(),
        );

        $qb->andWhere($qb->expr()->not(
            $qb->expr()->exists($qbal->getDQL()),
        ));

        $today = new DateTime();
        $active = FoundationRepository::getIsActiveWithinSubQuery(
            qb: $qb,
            activeAfter: $today,
            activeBefore: $today,
        );

        $qb->andWhere($qb->expr()->notIn(
            'm',
            $active->getDQL(),
        ));

        if (null !== $limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * This helper query is used for multiple queries to get the LAST membership of a member.
     * This is not necessarily the current membership.
     * We use the startDate because it is guaranteed to be unique in combination with member.lidnr.
     */
    private function lastMembershipQuery(string $memberAlias = 'm'): QueryBuilder
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('MAX(lastMem.startDate)')
            ->from(
                Membership::class,
                'lastMem',
            )
            ->where('lastMem.member = ' . $memberAlias);

        return $qb;
    }
}
