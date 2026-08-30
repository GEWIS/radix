<?php

declare(strict_types=1);

namespace App\Entity\Application\Traits;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;

/**
 * The claim an emailed link is exchanged for: a hash that is valid for one use and a short period, so the token
 * itself is not part of the address of the page behind it. Used by {@see \App\Entity\User\PasswordReset} and
 * {@see \App\Entity\Database\ActionLink}. The using class declares its own `#[Index]` on `tempHash`.
 */
trait TempHashTrait
{
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $tempHash = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $tempHashExpiresAt = null;

    public function isTempHashExpired(): bool
    {
        return null === $this->tempHashExpiresAt
            || $this->tempHashExpiresAt <= new DateTimeImmutable('now');
    }
}
