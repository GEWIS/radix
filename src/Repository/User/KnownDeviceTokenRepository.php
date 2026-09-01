<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\KnownDeviceToken;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends KnownFactRepository<KnownDeviceToken>
 */
class KnownDeviceTokenRepository extends KnownFactRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            KnownDeviceToken::class,
        );
    }

    /**
     * The token matching this hash, however long ago it was last seen.
     *
     * Whether it is recent enough to count as recognised is the caller's decision. Scoped to the account even though
     * the hash alone would find it: a cookie replayed against another account must find nothing.
     */
    public function findOneByTokenHash(
        string $userIdentifier,
        string $firewallName,
        string $tokenHash,
    ): ?KnownDeviceToken {
        return $this->createQueryBuilder('t')
            ->where('t.userIdentifier = :uid')
            ->andWhere('t.firewallName = :fw')
            ->andWhere('t.tokenHash = :th')
            ->setParameter(
                'uid',
                $userIdentifier,
            )
            ->setParameter(
                'fw',
                $firewallName,
            )
            ->setParameter(
                'th',
                $tokenHash,
            )
            ->getQuery()
            ->getOneOrNullResult();
    }
}
