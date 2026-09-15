<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Decision\SubDecision\Board\Installation as BoardInstallation;
use App\Repository\Decision\BoardMemberRepository;
use DateTimeImmutable;
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
    public Member $member;

    /**
     * Function given.
     */
    #[Column(
        type: Types::STRING,
        enumType: BoardFunctions::class,
    )]
    public BoardFunctions $function;

    /**
     * Installation date.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $installDate;

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
    public BoardInstallation $installationDec;

    /**
     * Release date.
     */
    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $releaseDate = null;

    /**
     * Discharge date.
     */
    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $dischargeDate = null;
}
