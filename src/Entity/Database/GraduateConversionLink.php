<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Database\Enums\GraduateConversionOutcome;
use App\Repository\Database\GraduateConversionLinkRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
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
    public private(set) Member $member;

    /**
     * What says whether an offer has already been made for a given ending.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public private(set) DateTimeImmutable $currentExpiration;

    #[Column(
        type: Types::STRING,
        enumType: GraduateConversionOutcome::class,
    )]
    public GraduateConversionOutcome $outcome = GraduateConversionOutcome::Pending;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) DateTimeImmutable $requestedOn;

    public function __construct(
        Member $member,
        DateTimeImmutable $currentExpiration,
    ) {
        parent::__construct();

        $this->member = $member;
        $this->currentExpiration = $currentExpiration;
        $this->requestedOn = new DateTimeImmutable();
    }

    #[Override]
    public function linkExpired(): bool
    {
        $diff = new DateTimeImmutable()->diff($this->currentExpiration);

        return 1 === $diff->invert && $diff->days > self::GRACE_DAYS;
    }
}
