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
 * A cookie handed to a browser at sign-in, so that the browser itself can say it has been here before.
 *
 * This is the one exact answer recognition has. The fingerprint describes a kind of browser and the network a place,
 * and both are shared with everybody alike; the cookie names the very browser profile, wherever it goes. It is no
 * credential: it is only ever consulted after a sign-in has already succeeded, and only suppresses the notice for the
 * one account it was minted on, so a stolen one is worth nothing without the password it rode along with.
 *
 * A browser that keeps no cookies simply never presents one and is carried by the fingerprint instead. The rows such
 * browsers leave behind are never presented again, sink to the bottom of the least-recently-seen order, and are the
 * first out when the cap is reached.
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
    /**
     * Keyed HMAC of the random value the cookie carries. Hashed so that reading this table does not yield cookies
     * that would quiet the notices on somebody else's account.
     */
    #[Column(type: Types::STRING)]
    private string $tokenHash;

    /**
     * Kept for display: what a member would recognise this cookie's browser as in their data export.
     */
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
     * The hash is left out, meaning nothing to the reader.
     *
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
