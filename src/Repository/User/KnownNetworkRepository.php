<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\KnownNetwork;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends KnownFactRepository<KnownNetwork>
 */
class KnownNetworkRepository extends KnownFactRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            KnownNetwork::class,
        );
    }

    /**
     * The network matching this fingerprint, however long ago it was last seen.
     *
     * Whether it is recent enough to count as recognised is the caller's decision. A stale row must still be found,
     * because the unique constraint would refuse a second one beside it.
     */
    public function findOneByFingerprint(
        string $userIdentifier,
        string $firewallName,
        string $fingerprint,
    ): ?KnownNetwork {
        return $this->createQueryBuilder('n')
            ->where('n.userIdentifier = :uid')
            ->andWhere('n.firewallName = :fw')
            ->andWhere('n.fingerprint = :fp')
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
}
