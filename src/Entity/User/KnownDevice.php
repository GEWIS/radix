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
 * A device an account has already signed in from, so that signing in again from it does not raise a notice the member
 * has no reason to read.
 *
 * This is the memory {@see Session} cannot be: a session row is deleted on sign-out and left unusable by a closed
 * private window, so it says which devices are signed in and never which have been seen before.
 *
 * Deliberately says nothing about where the device was: the network it arrived from is learned separately as
 * {@see KnownNetwork}, so that a laptop first seen at home is not a stranger the day it is opened on campus.
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
    /**
     * Keyed HMAC of the browser family, the operating system family and the languages it asks for. Hashed rather than
     * stored plainly, so this table does not become a second record of what every member reads with.
     */
    #[Column(type: Types::STRING)]
    private string $fingerprint;

    /**
     * Kept for display, and deliberately not part of the fingerprint: a version bump every few weeks would otherwise
     * make each of these a different device.
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
     * The device as somebody would recognise it. The fingerprint is left out, being a keyed hash that means nothing to
     * the reader.
     *
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
