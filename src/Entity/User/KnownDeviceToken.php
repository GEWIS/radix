<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Repository\User\KnownDeviceTokenRepository;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * A cookie handed to a browser at sign-in, so the browser itself can say it has been here before, wherever it goes.
 * It is no credential: it only suppresses the notice for the one account it was minted on, so a stolen one is worth
 * nothing without the password it rode along with.
 *
 * @phpstan-type KnownDeviceTokenGdprArrayType = array{
 *     firewall: string,
 *     browser: ?string,
 *     operatingSystem: ?string,
 *     firstSeenAt: string,
 *     lastSeenAt: string,
 * }
 */
#[Entity(repositoryClass: KnownDeviceTokenRepository::class)]
#[UniqueConstraint(fields: ['userIdentifier', 'firewallName', 'tokenHash'])]
#[Index(fields: ['lastSeenAt'])]
class KnownDeviceToken extends KnownFact
{
    /** Keyed HMAC, so reading this table yields no cookie that would quiet somebody's notices. */
    #[Column(type: Types::STRING)]
    private string $tokenHash;

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

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
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
     * @return KnownDeviceTokenGdprArrayType
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
