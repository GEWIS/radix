<?php

declare(strict_types=1);

namespace App\Repository\Database;

use App\Entity\Database\ActionLink;
use App\Entity\Database\EmailChangeLink;
use App\Entity\Database\Member;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\RenewalLink;
use DateInterval;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionLink>
 */
class ActionLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            ActionLink::class,
        );
    }

    /**
     * A selector is half a token: the caller checks the verifier against {@see ActionLink::tokenMatches()}.
     */
    public function findPaymentBySelector(string $selector): ?PaymentLink
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('pl, m')
            ->from(
                PaymentLink::class,
                'pl',
            )
            ->leftJoin(
                'pl.prospectiveMember',
                'm',
            )
            ->where('pl.selector = :selector');

        $qb->setParameter(
            'selector',
            $selector,
        );

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findPaymentByProspectiveMember(int $lidnr): ?PaymentLink
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('pl')
            ->from(
                PaymentLink::class,
                'pl',
            )
            ->where('pl.prospectiveMember = :lidnr');

        $qb->setParameter(
            ':lidnr',
            $lidnr,
        );

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * As {@see self::findPaymentBySelector()}, for a renewal link.
     */
    public function findRenewalBySelector(string $selector): ?RenewalLink
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('rl, m')
            ->from(
                RenewalLink::class,
                'rl',
            )
            ->leftJoin(
                'rl.member',
                'm',
            )
            ->where('rl.selector = :selector');

        $qb->setParameter(
            'selector',
            $selector,
        );

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * As {@see self::findPaymentBySelector()}, for a change of e-mail address.
     */
    public function findEmailChangeBySelector(string $selector): ?EmailChangeLink
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('el, m')
            ->from(
                EmailChangeLink::class,
                'el',
            )
            ->leftJoin(
                'el.member',
                'm',
            )
            ->where('el.selector = :selector');

        $qb->setParameter(
            'selector',
            $selector,
        );

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByTempHash(string $tempHash): ?ActionLink
    {
        return $this->findOneBy(['tempHash' => $tempHash]);
    }

    /**
     * Get all renewal links for a member
     *
     * @return array<array-key, RenewalLink>|null
     */
    public function findRenewalByMember(int $lidnr): ?array
    {
        return $this->getEntityManager()->getRepository(RenewalLink::class)->findBy(['member' => $lidnr]);
    }

    /**
     * Create a renewal link for a member.
     *
     * If no expiration date is given, we renew until the first July 1st after the current expiration date +
     * at most an extra 31 days to prevent two renewals within one month.
     */
    public function createRenewalByMember(
        Member $member,
        ?DateTime $newExpiration = null,
    ): ?RenewalLink {
        if (null === $newExpiration) {
            $newExpiration = new DateTime();
            // Expire at midnight on July 1st, renewing at most 366 + 31 days
            $newExpiration->setTime(
                0,
                0,
            );
            $newExpiration->setDate(
                ((int) $member->getExpiration()->format('Y')) + 1,
                7,
                1,
            );

            while ($newExpiration->diff($member->getExpiration())->days > 397) {
                $newExpiration->sub(new DateInterval('P1Y'));
            }
        }

        $actionLink = new RenewalLink(
            $member,
            $newExpiration,
        );
        $this->persist($actionLink);

        return $actionLink;
    }

    public function remove(ActionLink $link): void
    {
        $this->getEntityManager()->remove($link);
        $this->getEntityManager()->flush();
    }

    public function persist(ActionLink $link): void
    {
        $this->getEntityManager()->persist($link);
        $this->getEntityManager()->flush();
    }
}
