<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Database\Enums\GraduateConversionOutcome;
use App\Repository\Database\GraduateConversionLinkRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Override;

#[Entity(repositoryClass: GraduateConversionLinkRepository::class)]
class GraduateConversionLink extends ActionLink
{
    public const int GRACE_DAYS = 30;

    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'member',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'cascade',
    )]
    private Member $member;

    /**
     * What says whether an offer has already been made for a given ending.
     */
    #[Column(type: 'date')]
    private DateTime $currentExpiration;

    #[Column(
        type: 'string',
        enumType: GraduateConversionOutcome::class,
    )]
    private GraduateConversionOutcome $outcome = GraduateConversionOutcome::Pending;

    #[Column(type: 'datetime_immutable')]
    private DateTimeImmutable $requestedOn;

    public function __construct(
        Member $member,
        DateTime $currentExpiration,
    ) {
        parent::__construct();

        $this->member = $member;
        $this->currentExpiration = $currentExpiration;
        $this->requestedOn = new DateTimeImmutable();
    }

    public function getMember(): Member
    {
        return $this->member;
    }

    public function getCurrentExpiration(): DateTime
    {
        return $this->currentExpiration;
    }

    public function getOutcome(): GraduateConversionOutcome
    {
        return $this->outcome;
    }

    public function setOutcome(GraduateConversionOutcome $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function getRequestedOn(): DateTimeImmutable
    {
        return $this->requestedOn;
    }

    #[Override]
    public function linkExpired(): bool
    {
        $diff = new DateTime()->diff($this->currentExpiration);

        return 1 === $diff->invert && $diff->days > self::GRACE_DAYS;
    }
}
