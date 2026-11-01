<?php

declare(strict_types=1);

namespace App\Repository\Activity;

use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Decision\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function array_column;

/**
 * @extends ServiceEntityRepository<UserSignup>
 */
class UserSignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            UserSignup::class,
        );
    }

    /**
     * Get all sign-ups for a specific member.
     *
     * @return UserSignup[]
     */
    public function findSignupsByMember(Member $member): array
    {
        $qb = $this->createQueryBuilder('s');
        $qb->where('s.user = :member')
            ->setParameter(
                'member',
                $member->lidnr,
            );

        return $qb->getQuery()->getResult();
    }

    /**
     * The member's own sign-up on a list, if any. Drives double-sign-up prevention and targets the edit/unsubscribe
     * actions.
     */
    public function findOneByListAndMember(
        SignupList $signupList,
        Member $member,
    ): ?UserSignup {
        return $this->findOneBy([
            'signupList' => $signupList,
            'user' => $member,
        ]);
    }

    public function getNumberOfSignedUpMembers(SignupList $signupList): int
    {
        $qb = $this->createQueryBuilder('s');
        $qb->select('COUNT(s)')
            ->where('s.signupList = :signupList')
            ->setParameter(
                'signupList',
                $signupList,
                SignupList::class,
            );

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * IDs of the given signup lists that the member is signed up for.
     *
     * Scoped to the page's lists (not the member's entire history) and returning scalar IDs only (no entity
     * hydration, no lazy loads). Uses IDENTITY() to read the FK column directly without joining the SignupList table.
     *
     * @param list<int> $listIds
     *
     * @return list<int> IDs of the lists the member is signed up for
     */
    public function findSignedUpListIds(
        Member $member,
        array $listIds,
    ): array {
        if ([] === $listIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('s');
        $qb->select('DISTINCT IDENTITY(s.signupList) AS listId')
            ->where('s.user = :member')
            ->andWhere('s.signupList IN (:lists)')
            ->setParameter(
                'member',
                $member->lidnr,
            )
            ->setParameter(
                'lists',
                $listIds,
            );

        return array_column(
            $qb->getQuery()->getScalarResult(),
            'listId',
        );
    }
}
