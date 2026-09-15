<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\SubDecision\Foundation;
use App\Repository\Decision\OrganRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;

use function usort;

/**
 * Organ entity.
 *
 * Note that this entity is derived from the decisions themselves.
 *
 * ORM 2 emitted a `<field>_uniq` unique index for the join columns of a one-to-one owning side; ORM 3 emits a plain
 * foreign-key index instead. Declared here so the relation stays one-to-one in the database, under the name the
 * existing schema already uses.
 */
#[UniqueConstraint(
    name: 'foundation_uniq',
    columns: [
        'r_meeting_type',
        'r_meeting_number',
        'r_decision_point',
        'r_decision_number',
        'r_sequence',
    ],
)]
#[Entity(repositoryClass: OrganRepository::class)]
#[Queryable]
class Organ
{
    use IdentifiableTrait;

    /**
     * Abbreviation (only for when organs are created).
     */
    #[Column(type: Types::STRING)]
    public string $abbr;

    /**
     * Name (only for when organs are created).
     */
    #[Column(type: Types::STRING)]
    public string $name;

    /**
     * Type of the organ.
     */
    #[Column(
        type: Types::STRING,
        enumType: OrganTypes::class,
    )]
    public OrganTypes $type;

    /**
     * Reference to foundation of organ.
     */
    #[OneToOne(
        inversedBy: 'organ',
        targetEntity: Foundation::class,
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
    public Foundation $foundation;

    /**
     * Foundation date.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $foundationDate;

    /**
     * Abrogation date.
     */
    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $abrogationDate = null;

    /**
     * Reference to members.
     *
     * Membership of a body is derived from the decisions about that body, so it cannot outlast the body itself: a
     * body whose foundation was annulled never existed, and neither did anyone's membership of it. Deleting the body
     * therefore deletes the memberships derived from it, which the database is deliberately not told to do — what
     * goes and what stays is the projection's to decide rather than a cascade's to settle behind its back.
     *
     * @var Collection<array-key, OrganMember>
     */
    #[OneToMany(
        mappedBy: 'organ',
        targetEntity: OrganMember::class,
        cascade: ['remove'],
    )]
    private Collection $members;

    /**
     * Reference to subdecisions.
     *
     * @var Collection<array-key, SubDecision>
     */
    #[ManyToMany(
        targetEntity: SubDecision::class,
        cascade: ['persist'],
    )]
    #[JoinTable(name: 'organs_subdecisions')]
    #[JoinColumn(
        name: 'organ_id',
        referencedColumnName: 'id',
    )]
    #[InverseJoinColumn(
        name: 'meeting_type',
        referencedColumnName: 'meeting_type',
    )]
    #[InverseJoinColumn(
        name: 'meeting_number',
        referencedColumnName: 'meeting_number',
    )]
    #[InverseJoinColumn(
        name: 'decision_point',
        referencedColumnName: 'decision_point',
    )]
    #[InverseJoinColumn(
        name: 'decision_number',
        referencedColumnName: 'decision_number',
    )]
    #[InverseJoinColumn(
        name: 'subdecision_sequence',
        referencedColumnName: 'sequence',
    )]
    private Collection $subdecisions;

    /**
     * The body's page on the website, or null while nobody has started one.
     */
    #[OneToOne(
        mappedBy: 'organ',
        targetEntity: OrganInformation::class,
        cascade: [
            'persist',
            'remove',
        ],
    )]
    public ?OrganInformation $organInformation = null;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->subdecisions = new ArrayCollection();
    }

    /**
     * Get the members.
     *
     * @return Collection<array-key, OrganMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * Add a member.
     *
     * Kept in step with the owning side, so that a member installed earlier in the same meeting is already part of the
     * organ when a later decision in that meeting asks who is in it.
     */
    public function addMember(OrganMember $member): void
    {
        if ($this->members->contains($member)) {
            return;
        }

        $this->members[] = $member;
    }

    /**
     * Add multiple subdecisions.
     *
     * @param SubDecision[] $subdecisions
     */
    public function addSubdecisions(array $subdecisions): void
    {
        foreach ($subdecisions as $subdecision) {
            $this->addSubdecision($subdecision);
        }
    }

    /**
     * Add a subdecision.
     */
    public function addSubdecision(SubDecision $subdecision): void
    {
        if ($this->subdecisions->contains($subdecision)) {
            return;
        }

        $this->subdecisions[] = $subdecision;
    }

    /**
     * Remove a subdecision, if it is related to this organ.
     */
    public function removeSubdecision(SubDecision $subdecision): void
    {
        if (!$this->subdecisions->contains($subdecision)) {
            return;
        }

        $this->subdecisions->removeElement($subdecision);
    }

    /**
     * Get all subdecisions of this organ ordered by upload order.
     *
     * @return SubDecision[] subdecisions[0]->getDate < subdecision[1]->getDate
     */
    public function getOrderedSubdecisions(): array
    {
        $array = $this->subdecisions->toArray();
        usort(
            $array,
            static function (SubDecision $dA, SubDecision $dB) {
                // Compare the meeting dates first (note that we compare B against A).
                $dateComparison = $dB->decision->meeting->date
                    <=> $dA->decision->meeting->date;

                if (0 === $dateComparison) {
                    // If the meeting dates are equal, compare the sequence numbers (note that we compare B against A).
                    return $dB->sequence <=> $dA->sequence;
                }

                return $dateComparison;
            },
        );

        return $array;
    }

    public function isAbrogated(): bool
    {
        return null !== $this->abrogationDate
            && (new DateTimeImmutable()) >= $this->abrogationDate;
    }
}
