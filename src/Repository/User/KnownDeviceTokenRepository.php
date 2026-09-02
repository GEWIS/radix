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
     * Scoped to the account even though the hash alone would find it: a cookie replayed against another account must
     * find nothing.
     */
    public function findOneByTokenHash(
        string $userIdentifier,
        string $firewallName,
        string $tokenHash,
    ): ?KnownDeviceToken {
        return $this->findOneBy([
            'userIdentifier' => $userIdentifier,
            'firewallName' => $firewallName,
            'tokenHash' => $tokenHash,
        ]);
    }
}
