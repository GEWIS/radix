<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\EmailChangeLinkRepository;
use DateInterval;
use DateTimeImmutable;
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
    private Member $member;

    #[Column(type: 'string')]
    private string $newEmail;

    /**
     * Kept here because by the time the change takes effect the member no longer answers with it.
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    private ?string $previousEmail;

    #[Column(type: 'datetime_immutable')]
    private DateTimeImmutable $requestedOn;

    public function __construct(
        Member $member,
        string $newEmail,
    ) {
        parent::__construct();

        $this->member = $member;
        $this->newEmail = $newEmail;
        $this->previousEmail = $member->getEmail();
        $this->requestedOn = new DateTimeImmutable();
    }

    public function getMember(): Member
    {
        return $this->member;
    }

    public function getNewEmail(): string
    {
        return $this->newEmail;
    }

    public function getPreviousEmail(): ?string
    {
        return $this->previousEmail;
    }

    public function getRequestedOn(): DateTimeImmutable
    {
        return $this->requestedOn;
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
