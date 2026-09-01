<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\KnownFact;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * As with sessions, every query that reads or deletes facts for a user MUST also filter by firewallName: a fact
 * recognised on `main` says nothing about the same browser arriving at `company`.
 *
 * @template T of KnownFact
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class KnownFactRepository extends ServiceEntityRepository
{
    /**
     * Every fact recorded for a user identifier, used to include them in a data export.
     *
     * @return T[]
     */
    public function findAllByUser(string $userIdentifier): array
    {
        return $this->findBy(
            ['userIdentifier' => $userIdentifier],
            ['lastSeenAt' => 'DESC'],
        );
    }

    public function countForUserOnFirewall(
        string $userIdentifier,
        string $firewallName,
    ): int {
        return $this->count([
            'userIdentifier' => $userIdentifier,
            'firewallName' => $firewallName,
        ]);
    }

    /**
     * The facts a user has gone longest without being seen with, oldest first.
     *
     * @return T[]
     */
    public function findLeastRecentlySeen(
        string $userIdentifier,
        string $firewallName,
        int $limit,
    ): array {
        return $this->findBy(
            [
                'userIdentifier' => $userIdentifier,
                'firewallName' => $firewallName,
            ],
            ['lastSeenAt' => 'ASC'],
            $limit,
        );
    }

    public function deleteAllForUserOnFirewall(
        string $userIdentifier,
        string $firewallName,
    ): int {
        return $this->createQueryBuilder('f')
            ->delete()
            ->where('f.userIdentifier = :uid')
            ->andWhere('f.firewallName = :fw')
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
        return $this->createQueryBuilder('f')
            ->delete()
            ->where('f.lastSeenAt <= :before')
            ->setParameter(
                'before',
                $before,
            )
            ->getQuery()
            ->execute();
    }
}
