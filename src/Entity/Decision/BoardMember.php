<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Decision\SubDecision\Board\Installation as BoardInstallation;
use App\Repository\Decision\BoardMemberRepository;
use DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * Board member entity.
 *
 * Note that this entity is derived from the decisions themselves.
 *
 * ORM 2 emitted a `<field>_uniq` unique index for the join columns of a one-to-one owning side; ORM 3 emits a plain
 * foreign-key index instead. Declared here so the relation stays one-to-one in the database, under the name the
 * existing schema already uses.
 */
#[UniqueConstraint(
    name: 'installationDec_uniq',
    columns: [
        'r_meeting_type',
        'r_meeting_number',
        'r_decision_point',
        'r_decision_number',
        'r_sequence',
    ],
)]
#[Entity(repositoryClass: BoardMemberRepository::class)]
#[Queryable]
class BoardMember
{
    use IdentifiableTrait;

    /**
     * Member lidnr. Deliberately without an `onDelete`: this is who an installation decision put on the board, so the
     * constraint refusing a member's removal is the point rather than an obstacle.
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'boardInstallations',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        nullable: false,
    )]
    private Member $member;

    /**
     * Function given.
     */
    #[Column(
        type: Types::STRING,
        enumType: BoardFunctions::class,
    )]
    private BoardFunctions $function;

    /**
     * Installation date.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    private DateTime $installDate;

    /**
     * Installation.
     */
    #[OneToOne(
        targetEntity: BoardInstallation::class,
        inversedBy: 'boardMember',
    )]
    #[JoinColumn(
        name: 'r_meeting_type',
        referencedColumnName: 'meeting_type',
    )]
    #[JoinColumn(
        name: 'r_meeting_number',
        referencedColumnName: 'meeting_number',
    )]
    #[JoinColumn(
        name: 'r_decision_point',
        referencedColumnName: 'decision_point',
    )]
    #[JoinColumn(
        name: 'r_decision_number',
        referencedColumnName: 'decision_number',
    )]
    #[JoinColumn(
        name: 'r_sequence',
        referencedColumnName: 'sequence',
    )]
    private BoardInstallation $installationDec;

    /**
     * Release date.
     */
    #[Column(
        type: Types::DATE_MUTABLE,
        nullable: true,
    )]
    private ?DateTime $releaseDate = null;

    /**
     * Discharge date.
     */
    #[Column(
        type: Types::DATE_MUTABLE,
        nullable: true,
    )]
    private ?DateTime $dischargeDate = null;

    /**
     * Get the member.
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }

    /**
     * Get the function.
     */
    public function getFunction(): BoardFunctions
    {
        return $this->function;
    }

    /**
     * Set the function.
     */
    public function setFunction(BoardFunctions $function): void
    {
        $this->function = $function;
    }

    /**
     * Get the installation date.
     */
    public function getInstallDate(): DateTime
    {
        return $this->installDate;
    }

    /**
     * Set the installation date.
     */
    public function setInstallDate(DateTime $installDate): void
    {
        $this->installDate = $installDate;
    }

    /**
     * Get the installation decision.
     */
    public function getInstallationDec(): BoardInstallation
    {
        return $this->installationDec;
    }

    /**
     * Set the installation decision.
     */
    public function setInstallationDec(BoardInstallation $installationDec): void
    {
        $this->installationDec = $installationDec;
    }

    /**
     * Get the release date.
     */
    public function getReleaseDate(): ?DateTime
    {
        return $this->releaseDate;
    }

    /**
     * Set the release date.
     */
    public function setReleaseDate(?DateTime $releaseDate): void
    {
        $this->releaseDate = $releaseDate;
    }

    /**
     * Get the discharge date.
     */
    public function getDischargeDate(): ?DateTime
    {
        return $this->dischargeDate;
    }

    /**
     * Set the discharge date.
     */
    public function setDischargeDate(?DateTime $dischargeDate): void
    {
        $this->dischargeDate = $dischargeDate;
    }
}
