<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\EmailChangeLinkRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Override;

#[Entity(repositoryClass: EmailChangeLinkRepository::class)]
class EmailChangeLink extends ActionLink
{
    private const string LIFETIME = 'P1D';

    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'member',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'cascade',
    )]
    public private(set) Member $member;

    #[Column(type: Types::STRING)]
    public private(set) string $newEmail;

    /**
     * Stored here because the member record no longer has it once the change takes effect.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public private(set) ?string $previousEmail;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) DateTimeImmutable $requestedOn;

    public function __construct(
        Member $member,
        string $newEmail,
    ) {
        parent::__construct();

        $this->member = $member;
        $this->newEmail = $newEmail;
        $this->previousEmail = $member->email;
        $this->requestedOn = new DateTimeImmutable();
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->requestedOn->add(new DateInterval(self::LIFETIME));
    }

    #[Override]
    public function linkExpired(): bool
    {
        return $this->getExpiresAt() <= new DateTimeImmutable();
    }
}
