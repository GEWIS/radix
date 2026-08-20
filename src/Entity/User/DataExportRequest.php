<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Repository\User\DataExportRequestRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * Records that a member asked for a data export, so a repeated request while one is still being prepared or is still
 * downloadable can be refused.
 */
#[Entity(repositoryClass: DataExportRequestRepository::class)]
class DataExportRequest
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: Types::INTEGER)]
    private int $id;

    /**
     * Whose export this was. The request exists only to rate-limit that account, so it is pointless once the account
     * is gone — and holding on to a record of who asked for their data would be the opposite of the point.
     */
    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(
        name: 'user_id',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private User $user;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $requestedAt;

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(DateTimeImmutable $requestedAt): void
    {
        $this->requestedAt = $requestedAt;
    }
}
