<?php

declare(strict_types=1);

namespace App\Repository\Database;

use App\Entity\Database\GraduateConversionLink;
use App\Entity\Database\Member;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use function array_map;

/**
 * @extends ServiceEntityRepository<GraduateConversionLink>
 */
class GraduateConversionLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            GraduateConversionLink::class,
        );
    }

    /**
     * @return GraduateConversionLink[]
     */
    public function findOutstandingForMember(Member $member): array
    {
        return $this->findBy([
            'member' => $member,
            'used' => false,
        ]);
    }

    /**
     * The members who have been asked and whose offer is still open, by membership number.
     *
     * @return int[]
     */
    public function findMembersWithAnOpenOffer(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.member) AS lidnr')
            ->where('l.used = false')
            ->andWhere('l.currentExpiration >= :stillOpen')
            ->setParameter(
                'stillOpen',
                new DateTime()->modify('-' . GraduateConversionLink::GRACE_DAYS . ' days')->setTime(
                    0,
                    0,
                ),
            )
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): int => (int) $row['lidnr'],
            $rows,
        );
    }
}
