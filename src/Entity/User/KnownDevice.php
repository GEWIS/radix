<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\User\KnownDeviceRepository;
use DateTimeImmutable;
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
 * Nothing here decides whether somebody may sign in, only whether they are told about it afterwards.
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
class KnownDevice
{
    use IdentifiableTrait;

    /**
     * The account this device was seen on (a membership number or an email address), the same value
     * {@see Session::$userIdentifier} holds.
     */
    #[Column(type: Types::STRING)]
    private string $userIdentifier;

    /**
     * Which Symfony firewall the device was seen on. Recognition is scoped per firewall for the reason sessions are:
     * the two account spaces are unrelated.
     */
    #[Column(type: Types::STRING)]
    private string $firewallName;

    /**
     * Keyed HMAC of the browser family, the operating system family, the network the request came from and the
     * languages it asks for. Hashed rather than stored plainly, so this table does not become a second record of where
     * every member has been and what they read.
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

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $firstSeenAt;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastSeenAt;

    public function getUserIdentifier(): string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(string $userIdentifier): void
    {
        $this->userIdentifier = $userIdentifier;
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    public function setFirewallName(string $firewallName): void
    {
        $this->firewallName = $firewallName;
    }

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

    public function getFirstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(DateTimeImmutable $firstSeenAt): void
    {
        $this->firstSeenAt = $firstSeenAt;
    }

    public function getLastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
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
