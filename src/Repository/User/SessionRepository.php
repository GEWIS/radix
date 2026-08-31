<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\Session;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Session>
 *
 * NOTE: All queries that list, count, or delete sessions for a user MUST
 * also filter by firewallName. This guarantees that:
 *   - A "terminate all" action on the `main` firewall does not touch `company` sessions.
 *   - The session management UI shows only the sessions relevant to the current user context.
 *
 * The only exception is findOneBySeries(), which is looked up globally (series
 * values are globally unique) – the firewall ownership is then verified
 * by the handler after the lookup.
 */
class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            Session::class,
        );
    }

    public function findOneBySeries(string $series): ?Session
    {
        return $this->findOneBy(['series' => $series]);
    }

    /** Conditional on the token read still being current, so two racing requests cannot overwrite one another. */
    public function rotateToken(
        string $series,
        string $currentHashedToken,
        string $newHashedToken,
        string $signature,
        DateTimeImmutable $previousTokenValidUntil,
        DateTimeImmutable $lastUsedAt,
    ): bool {
        return 1 === $this->createQueryBuilder('s')
            ->update()
            ->set(
                's.hashedToken',
                ':new',
            )
            ->set(
                's.signature',
                ':signature',
            )
            ->set(
                's.previousHashedToken',
                ':previous',
            )
            ->set(
                's.previousTokenValidUntil',
                ':validUntil',
            )
            ->set(
                's.lastUsedAt',
                ':lastUsedAt',
            )
            ->where('s.series = :series')
            ->andWhere('s.hashedToken = :current')
            ->setParameter(
                'new',
                $newHashedToken,
            )
            ->setParameter(
                'signature',
                $signature,
            )
            ->setParameter(
                'previous',
                $currentHashedToken,
            )
            ->setParameter(
                'validUntil',
                $previousTokenValidUntil,
            )
            ->setParameter(
                'lastUsedAt',
                $lastUsedAt,
            )
            ->setParameter(
                'series',
                $series,
            )
            ->setParameter(
                'current',
                $currentHashedToken,
            )
            ->getQuery()
            ->execute();
    }

    /**
     * Read past the identity map, so a caller that lost a conditional update sees what the winner wrote.
     *
     * @return array{previousHashedToken: string|null, previousTokenValidUntil: DateTimeImmutable|null}|null
     */
    public function findRotationGrace(string $series): ?array
    {
        /** @var array{previousHashedToken: string|null, previousTokenValidUntil: DateTimeImmutable|null}|null $row */
        $row = $this->createQueryBuilder('s')
            ->select(
                's.previousHashedToken',
                's.previousTokenValidUntil',
            )
            ->where('s.series = :series')
            ->setParameter(
                'series',
                $series,
            )
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    public function findOneByPhpSessionId(string $phpSessionId): ?Session
    {
        return $this->findOneBy(['phpSessionId' => $phpSessionId]);
    }

    /** @return Session[] */
    public function findActiveByUserOnFirewall(
        string $userIdentifier,
        string $firewallName,
    ): array {
        return $this->createQueryBuilder('s')
            ->where('s.userIdentifier = :uid')
            ->andWhere('s.firewallName = :fw')
            ->andWhere('s.expiresAt > :now')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->setParameter(
                'fw',
                $firewallName,
            )
            ->setParameter(
                'now',
                new DateTimeImmutable(),
            )
            ->orderBy(
                's.lastUsedAt',
                'DESC',
            )
            ->getQuery()
            ->getResult();
    }

    /** @return Session[] */
    public function findAllByUserOnFirewall(
        string $userIdentifier,
        string $firewallName,
    ): array {
        return $this->createQueryBuilder('s')
            ->where('s.userIdentifier = :uid')
            ->andWhere('s.firewallName = :fw')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->setParameter(
                'fw',
                $firewallName,
            )
            ->orderBy(
                's.lastUsedAt',
                'DESC',
            )
            ->getQuery()
            ->getResult();
    }

    /**
     * Every session (active or expired) recorded for a user identifier, used to include them in a data export.
     *
     * @return Session[]
     */
    public function findAllByUser(string $userIdentifier): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.userIdentifier = :uid')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->orderBy(
                's.lastUsedAt',
                'DESC',
            )
            ->getQuery()
            ->getResult();
    }

    public function deleteAllForUserOnFirewall(
        string $userIdentifier,
        string $firewallName,
    ): int {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.userIdentifier = :uid')
            ->andWhere('s.firewallName = :fw')
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

    /**
     * Sessions nobody has used since `$before`, whatever their expiry says.
     *
     * `expiresAt` is fixed when the row is written and never extended, so it says how old a session is and not how
     * long ago somebody used it. A private window that was closed rather than signed out of leaves a row no cookie can
     * ever reach again, which would otherwise sit in the member's device list for the rest of its ninety days.
     *
     * `lastUsedAt` is the honest measure: {@see \App\EventListener\User\StaleSessionGuardListener} bumps it on real
     * activity, so an abandoned row keeps the timestamp it was created with while a device in use keeps moving.
     */
    public function deleteIdleSince(DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.lastUsedAt <= :before')
            ->setParameter(
                'before',
                $before,
            )
            ->getQuery()
            ->execute();
    }

    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.expiresAt <= :now')
            ->setParameter(
                'now',
                new DateTimeImmutable(),
            )
            ->getQuery()
            ->execute();
    }
}
