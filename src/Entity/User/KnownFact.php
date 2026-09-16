<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\IdentifiableTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\MappedSuperclass;

/**
 * One thing sign-in recognition rests on, seen for one account on one firewall: a {@see KnownDevice}, a
 * {@see KnownNetwork} or a {@see KnownDeviceToken}. Nothing here decides whether a user may sign in, only whether
 * they are notified about it afterwards.
 */
#[MappedSuperclass]
abstract class KnownFact
{
    use IdentifiableTrait;

    #[Column(type: Types::STRING)]
    public string $userIdentifier;

    /** Scoped per firewall for the reason sessions are: the two account spaces are unrelated. */
    #[Column(type: Types::STRING)]
    public string $firewallName;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $firstSeenAt;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastSeenAt;
}
