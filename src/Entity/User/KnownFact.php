<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\IdentifiableTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\MappedSuperclass;

/**
 * One thing recognition rests on, seen for one account on one firewall: a device ({@see KnownDevice}), the network it
 * arrived from ({@see KnownNetwork}), or the cookie it carried ({@see KnownDeviceToken}). The three are learned and
 * age out independently; {@see \App\Service\User\KnownDeviceRegistry} says how they combine.
 *
 * Nothing here decides whether somebody may sign in, only whether they are told about it afterwards.
 */
#[MappedSuperclass]
abstract class KnownFact
{
    use IdentifiableTrait;

    /**
     * The account this was seen on (a membership number or an email address), the same value
     * {@see Session::$userIdentifier} holds.
     */
    #[Column(type: Types::STRING)]
    private string $userIdentifier;

    /**
     * Which Symfony firewall this was seen on. Recognition is scoped per firewall for the reason sessions are: the
     * two account spaces are unrelated.
     */
    #[Column(type: Types::STRING)]
    private string $firewallName;

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
}
