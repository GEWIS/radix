<?php

declare(strict_types=1);

namespace App\Repository\User;

use App\Entity\User\KnownDevice;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends KnownFactRepository<KnownDevice>
 */
class KnownDeviceRepository extends KnownFactRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            KnownDevice::class,
        );
    }

    /**
     * However long ago it was last seen: freshness is the caller's decision, and a stale row must still be found or
     * the unique constraint refuses the second one beside it.
     */
    public function findOneByFingerprint(
        string $userIdentifier,
        string $firewallName,
        string $fingerprint,
    ): ?KnownDevice {
        return $this->findOneBy([
            'userIdentifier' => $userIdentifier,
            'firewallName' => $firewallName,
            'fingerprint' => $fingerprint,
        ]);
    }
}
