<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Repository\User\KnownNetworkRepository;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * A network an account has already signed in from, learned apart from the device that did it so the two are not
 * announced per pairing.
 *
 * @phpstan-type KnownNetworkGdprArrayType = array{
 *     firewall: string,
 *     firstSeenAt: string,
 *     lastSeenAt: string,
 * }
 */
#[Entity(repositoryClass: KnownNetworkRepository::class)]
#[UniqueConstraint(fields: ['userIdentifier', 'firewallName', 'fingerprint'])]
#[Index(fields: ['lastSeenAt'])]
class KnownNetwork extends KnownFact
{
    /** Keyed HMAC, so this table is not a record of where every member has been. */
    #[Column(type: Types::STRING)]
    private string $fingerprint;

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    /**
     * @return KnownNetworkGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'firewall' => $this->getFirewallName(),
            'firstSeenAt' => $this->getFirstSeenAt()->format(DateTimeInterface::ATOM),
            'lastSeenAt' => $this->getLastSeenAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
