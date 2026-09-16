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
 * A cookie issued to a browser at sign-in, so the browser is recognised on a later sign-in, whatever network it is
 * on. It is no credential: it only suppresses the notice for the one account it was minted on, so a stolen one is
 * worth nothing without the password it was issued with.
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
    /** Keyed HMAC, so reading this table gives no cookie that would suppress a member's notices. */
    #[Column(type: Types::STRING)]
    public string $tokenHash;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $browser = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $operatingSystem = null;

    /**
     * @return KnownDeviceTokenGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'firewall' => $this->firewallName,
            'browser' => $this->browser,
            'operatingSystem' => $this->operatingSystem,
            'firstSeenAt' => $this->firstSeenAt->format(DateTimeInterface::ATOM),
            'lastSeenAt' => $this->lastSeenAt->format(DateTimeInterface::ATOM),
        ];
    }
}
