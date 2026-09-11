<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\Decision\AuthorizationRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * Authorization model.
 *
 * @phpstan-type AuthorizationGdprArrayType = array{
 *     meeting_number: int,
 *     createdAt: string,
 *     revokedAt: ?string,
 * }
 */
#[Entity(repositoryClass: AuthorizationRepository::class)]
#[UniqueConstraint(
    name: 'auth_idx',
    columns: [
        'authorizer',
        'recipient',
        'meetingNumber',
        'revokedAt',
    ],
)]
class Authorization
{
    use IdentifiableTrait;

    /**
     * Member submitting this authorization. An authorization is a private arrangement between two members for one
     * meeting and means nothing once either of them is gone, so it is removed with either.
     */
    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'authorizer',
        referencedColumnName: 'lidnr',
        onDelete: 'CASCADE',
    )]
    public Member $authorizer;

    /**
     * Member receiving this authorization.
     */
    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'recipient',
        referencedColumnName: 'lidnr',
        onDelete: 'CASCADE',
    )]
    public Member $recipient;

    /**
     * Meeting number.
     */
    #[Column(type: Types::INTEGER)]
    public int $meetingNumber;

    /**
     * When the authorization was made.
     */
    #[Column(type: Types::DATETIME_MUTABLE)]
    public DateTime $createdAt;

    /**
     * When the authorization was revoked.
     */
    #[Column(
        type: Types::DATETIME_MUTABLE,
        nullable: true,
    )]
    public ?DateTime $revokedAt = null;

    /**
     * @return AuthorizationGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'meeting_number' => $this->meetingNumber,
            'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
            'revokedAt' => $this->revokedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
