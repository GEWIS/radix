<?php

declare(strict_types=1);

namespace App\Entity\Application\Traits;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;

trait TempHashTrait
{
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    protected ?string $tempHash = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    protected ?DateTimeImmutable $tempHashExpiresAt = null;

    public function getTempHash(): ?string
    {
        return $this->tempHash;
    }

    public function setTempHash(?string $tempHash): void
    {
        $this->tempHash = $tempHash;
    }

    public function getTempHashExpiresAt(): ?DateTimeImmutable
    {
        return $this->tempHashExpiresAt;
    }

    public function setTempHashExpiresAt(?DateTimeImmutable $tempHashExpiresAt): void
    {
        $this->tempHashExpiresAt = $tempHashExpiresAt;
    }

    public function isTempHashExpired(): bool
    {
        return null === $this->tempHashExpiresAt
            || $this->tempHashExpiresAt <= new DateTimeImmutable('now');
    }
}
