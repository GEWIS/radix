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
    private Organ $organ;

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
    private Member $member;

    /**
     * Function given.
     */
    #[Column(
        type: Types::STRING,
        enumType: InstallationFunctions::class,
    )]
    private InstallationFunctions $function;

    /**
     * Installation date.
     */
    #[Column(type: Types::DATE_MUTABLE)]
    private DateTime $installDate;

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
    private Installation $installation;

    /**
     * Discharge date.
     */
    #[Column(
        type: Types::DATE_MUTABLE,
        nullable: true,
    )]
    private ?DateTime $dischargeDate = null;

    /**
     * Set the organ.
     */
    public function setOrgan(Organ $organ): void
    {
        $this->organ = $organ;
    }

    /**
     * Get the organ.
     */
    public function getOrgan(): Organ
    {
        return $this->organ;
    }

    /**
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }

    /**
     * Get the member.
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * Set the function.
     */
    public function setFunction(InstallationFunctions $function): void
    {
        $this->function = $function;
    }

    /**
     * Get the function.
     */
    public function getFunction(): InstallationFunctions
    {
        return $this->function;
    }

    /**
     * Set the installation date.
     */
    public function setInstallDate(DateTime $installDate): void
    {
        $this->installDate = $installDate;
    }

    /**
     * Get the installation date.
     */
    public function getInstallDate(): DateTime
    {
        return $this->installDate;
    }

    /**
     * Set the installation.
     */
    public function setInstallation(Installation $installation): void
    {
        $this->installation = $installation;
    }

    /**
     * Get the installation.
     */
    public function getInstallation(): Installation
    {
        return $this->installation;
    }

    /**
     * Set the discharge date.
     */
    public function setDischargeDate(?DateTime $dischargeDate): void
    {
        $this->dischargeDate = $dischargeDate;
    }

    /**
     * Get the discharge date.
     */
    public function getDischargeDate(): ?DateTime
    {
        return $this->dischargeDate;
    }

    /**
     * Get whether the organ membership has ended or was annulled
     */
    public function isCurrent(): bool
    {
        $now = new DateTime();

        return $this->getInstallDate() <= $now
            && (
                null === $this->getDischargeDate()
                || $this->getDischargeDate() >= $now
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
                'id' => $this->getOrgan()->getId(),
                'abbreviation' => $this->getOrgan()->getAbbr(),
            ],
            'function' => $this->getFunction()->value,
            'installDate' => $this->getInstallDate()->format(DateTimeInterface::ATOM),
            'dischargeDate' => $this->getDischargeDate()?->format(DateTimeInterface::ATOM),
            'current' => $this->isCurrent(),
        ];
    }
}
