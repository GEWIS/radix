<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\User\Enums\DeviceTypes;
use App\Repository\User\SessionRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;

/**
 * @phpstan-type SessionGdprArrayType = array{
 *     series: string,
 *     firewall: string,
 *     deviceType: string,
 *     browser: ?string,
 *     operatingSystem: ?string,
 *     ipAddress: string,
 *     userAgent: string,
 *     createdAt: string,
 *     lastUsedAt: string,
 *     expiresAt: string,
 *     expired: bool,
 * }
 */
#[Entity(repositoryClass: SessionRepository::class)]
#[Index(fields: ['userIdentifier', 'firewallName'])]
#[Index(fields: ['series'])]
#[Index(fields: ['expiresAt'])]
#[Index(fields: ['phpSessionId'])]
class Session
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    public private(set) int $id;

    /**
     * The public series identifier, persisted across token rotations.
     * Stored in the browser cookie alongside the raw token.
     */
    #[Column(
        type: Types::STRING,
        unique: true,
    )]
    public string $series;

    /**
     * SHA-256(rawToken). Raw token is never stored.
     */
    #[Column(type: Types::STRING)]
    public string $hashedToken;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $previousHashedToken = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $previousTokenValidUntil = null;

    /**
     * HMAC of the immutable row fields. Detects DB tampering.
     */
    #[Column(type: Types::STRING)]
    public string $signature;

    /**
     * SHA-256 hash of the user's signature_properties values at session creation.
     */
    #[Column(type: Types::STRING)]
    public string $signaturePropertiesHash;

    /**
     * Which Symfony firewall this session belongs to.
     */
    #[Column(type: Types::STRING)]
    public string $firewallName;

    /**
     * The user this session belongs to (e.g. email or UUID).
     */
    #[Column(type: Types::STRING)]
    public string $userIdentifier;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $expiresAt;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastUsedAt;

    #[Column(type: Types::TEXT)]
    public string $userAgent;

    #[Column(type: Types::STRING)]
    public string $ipAddress;

    /**
     * Semantic device class; resolved to an icon glyph at render time via {@see DeviceTypes::icon()}.
     */
    #[Column(enumType: DeviceTypes::class)]
    public DeviceTypes $deviceType;

    /**
     * Parsed from userAgent (e.g. "Chrome 124"); for bots contains the bot name on its own. Nullable when the User
     * Agent is empty or unrecognised.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $browser = null;

    /**
     * Parsed from userAgent (e.g. "Android 14"). Nullable for bots / empty UAs / OS-less environments.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $operatingSystem = null;

    /**
     * The PHP session ID this row is bound to. Allows for direct destruction of the device's session via the Redis
     * session handler.
     */
    #[Column(type: Types::STRING)]
    public string $phpSessionId;

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    /**
     * The non-secret device and timing details of the session. The token, its hashes, and the PHP session id are
     * deliberately left out.
     *
     * @return SessionGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'series' => $this->series,
            'firewall' => $this->firewallName,
            'deviceType' => $this->deviceType->value,
            'browser' => $this->browser,
            'operatingSystem' => $this->operatingSystem,
            'ipAddress' => $this->ipAddress,
            'userAgent' => $this->userAgent,
            'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
            'lastUsedAt' => $this->lastUsedAt->format(DateTimeInterface::ATOM),
            'expiresAt' => $this->expiresAt->format(DateTimeInterface::ATOM),
            'expired' => $this->isExpired(),
        ];
    }
}
