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
 * A network an account has already signed in from, learned apart from the device that did it.
 *
 * Members move between a handful of networks their whole membership long: home, campus, their phone's carrier. Were
 * the network part of the device fingerprint, each pairing of the two would be announced as a new device, and the
 * university alone spans enough address space to make that a weekly letter. Kept apart, a network is announced once
 * and then covers every device the account is known on.
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
    /**
     * Keyed HMAC of the network identifier {@see \App\Security\User\IpNetworkResolver} reduced the address to.
     * Hashed rather than stored plainly, so this table does not become a record of where every member has been.
     */
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
     * The fingerprint is left out, being a keyed hash that means nothing to the reader.
     *
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
