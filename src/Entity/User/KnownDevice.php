<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Repository\User\KnownDeviceRepository;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * A kind of device an account has already signed in from. Where it was is learned separately as {@see KnownNetwork},
 * so a laptop first seen at home is not a stranger the day it is opened on campus.
 *
 * @phpstan-type KnownDeviceGdprArrayType = array{
 *     firewall: string,
 *     browser: ?string,
 *     operatingSystem: ?string,
 *     firstSeenAt: string,
 *     lastSeenAt: string,
 * }
 */
#[Entity(repositoryClass: KnownDeviceRepository::class)]
#[UniqueConstraint(fields: ['userIdentifier', 'firewallName', 'fingerprint'])]
#[Index(fields: ['lastSeenAt'])]
class KnownDevice extends KnownFact
{
    /** Keyed HMAC, so this table is not a record of what every member reads with. */
    #[Column(type: Types::STRING)]
    private string $fingerprint;

    /** Display only; a version inside the fingerprint would make every browser update a new device. */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    private ?string $browser = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    private ?string $operatingSystem = null;

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
    }

    public function getBrowser(): ?string
    {
        return $this->browser;
    }

    public function setBrowser(?string $browser): void
    {
        $this->browser = $browser;
    }

    public function getOperatingSystem(): ?string
    {
        return $this->operatingSystem;
    }

    public function setOperatingSystem(?string $operatingSystem): void
    {
        $this->operatingSystem = $operatingSystem;
    }

    /**
     * @return KnownDeviceGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'firewall' => $this->getFirewallName(),
            'browser' => $this->getBrowser(),
            'operatingSystem' => $this->getOperatingSystem(),
            'firstSeenAt' => $this->getFirstSeenAt()->format(DateTimeInterface::ATOM),
            'lastSeenAt' => $this->getLastSeenAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
