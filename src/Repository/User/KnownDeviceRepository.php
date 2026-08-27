<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\KnownDevice;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<KnownDevice>
 *
 * As with sessions, every query that reads or deletes devices for a user MUST also filter by firewallName: a device
 * recognised on `main` says nothing about the same browser arriving at `company`.
 */
class KnownDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            KnownDevice::class,
        );
    }

    /**
     * The device matching this fingerprint, however long ago it was last seen.
     *
     * Whether it is recent enough to count as recognised is the caller's decision. A stale row must still be found,
     * because the unique constraint would refuse a second one beside it.
     */
    public function findOneByFingerprint(
        string $userIdentifier,
        string $firewallName,
        string $fingerprint,
    ): ?KnownDevice {
        return $this->createQueryBuilder('d')
            ->where('d.userIdentifier = :uid')
            ->andWhere('d.firewallName = :fw')
            ->andWhere('d.fingerprint = :fp')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->setParameter(
                'fw',
                $firewallName,
            )
            ->setParameter(
                'fp',
                $fingerprint,
            )
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Every device recorded for a user identifier, used to include them in a data export.
     *
     * @return KnownDevice[]
     */
    public function findAllByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.userIdentifier = :uid')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->orderBy(
                'd.lastSeenAt',
                'DESC',
            )
            ->getQuery()
            ->getResult();
    }

    public function countForUserOnFirewall(
        string $userIdentifier,
        string $firewallName,
    ): int {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.userIdentifier = :uid')
            ->andWhere('d.firewallName = :fw')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->setParameter(
                'fw',
                $firewallName,
            )
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The devices a user has gone longest without using, oldest first.
     *
     * @return KnownDevice[]
     */
    public function findLeastRecentlySeen(
        string $userIdentifier,
        string $firewallName,
        int $limit,
    ): array {
        return $this->createQueryBuilder('d')
            ->where('d.userIdentifier = :uid')
            ->andWhere('d.firewallName = :fw')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->setParameter(
                'fw',
                $firewallName,
            )
            ->orderBy(
                'd.lastSeenAt',
                'ASC',
            )
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function deleteAllForUserOnFirewall(
        string $userIdentifier,
        string $firewallName,
    ): int {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.userIdentifier = :uid')
            ->andWhere('d.firewallName = :fw')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->setParameter(
                'fw',
                $firewallName,
            )
            ->getQuery()
            ->execute();
    }

    public function deleteSeenBefore(DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.lastSeenAt <= :before')
            ->setParameter(
                'before',
                $before,
            )
            ->getQuery()
            ->execute();
    }
}
