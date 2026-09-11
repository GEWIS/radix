<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Decision\SubDecision\Installation;
use App\Repository\Decision\OrganMemberRepository;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * Organ member entity.
 *
 * Note that this entity is derived from the decisions themselves.
 *
 * ORM 2 emitted a `<field>_uniq` unique index for the join columns of a one-to-one owning side; ORM 3 emits a plain
 * foreign-key index instead. Declared here so the relation stays one-to-one in the database, under the name the
 * existing schema already uses.
 */
#[UniqueConstraint(
    name: 'installation_uniq',
    columns: [
        'r_meeting_type',
        'r_meeting_number',
        'r_decision_point',
        'r_decision_number',
        'r_sequence',
    ],
)]
#[Entity(repositoryClass: OrganMemberRepository::class)]
#[Queryable]
class OrganMember
{
    use IdentifiableTrait;

    /**
     * Organ.
     */
    #[ManyToOne(
        targetEntity: Organ::class,
        inversedBy: 'members',
    )]
    public Organ $organ;

    /**
     * Member. Deliberately without an `onDelete`: this is who an installation decision put in the body, so the
     * constraint refusing a member's removal is the point rather than an obstacle.
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'organInstallations',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
    )]
    public Member $member;

    /**
     * Function given.
     */
    #[Column(
        type: Types::STRING,
        enumType: InstallationFunctions::class,
    )]
    public InstallationFunctions $function;

    /**
     * Installation date.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    public DateTime $installDate;

    /**
     * Installation.
     */
    #[OneToOne(
        targetEntity: Installation::class,
        inversedBy: 'organMember',
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
    public Installation $installation;

    /**
     * Discharge date.
     */
    #[Column(
        type: Types::DATE_MUTABLE,
        nullable: true,
    )]
    public ?DateTime $dischargeDate = null;

    /**
     * Get whether the organ membership has ended or was annulled
     */
    public function isCurrent(): bool
    {
        $now = new DateTime();

        return $this->installDate <= $now
            && (
                null === $this->dischargeDate
                || $this->dischargeDate >= $now
            );
    }

    /**
     * Convert the organ member to an array
     *
     * @return array{
     *     organ: array{
     *         id: int,
     *         abbreviation: string,
     *     },
     *     function: string,
     *     installDate: string,
     *     dischargeDate: ?string,
     *     current: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'organ' => [
                'id' => $this->organ->getId(),
                'abbreviation' => $this->organ->abbr,
            ],
            'function' => $this->function->value,
            'installDate' => $this->installDate->format(DateTimeInterface::ATOM),
            'dischargeDate' => $this->dischargeDate?->format(DateTimeInterface::ATOM),
            'current' => $this->isCurrent(),
        ];
    }
}
