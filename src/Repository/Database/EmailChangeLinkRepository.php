<?php

declare(strict_types=1);

namespace App\Repository\Database;

use App\Entity\Database\EmailChangeLink;
use App\Entity\Database\Member;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailChangeLink>
 */
class EmailChangeLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            EmailChangeLink::class,
        );
    }

    /**
     * A member who asks again supersedes what they asked before.
     */
    public function removeAllForMember(Member $member): void
    {
        $this->createQueryBuilder('l')
            ->delete()
            ->where('l.member = :member')
            ->setParameter(
                'member',
                $member,
            )
            ->getQuery()
            ->execute();
    }
}
