<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision;

use App\Doctrine\Query\Queryable;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Organ;
use App\Entity\Decision\SubDecision;
use App\Repository\Decision\SubDecision\FoundationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;

use function sprintf;

/**
 * Foundation of an organ.
 */
#[Entity(repositoryClass: FoundationRepository::class)]
#[Queryable]
class Foundation extends SubDecision
{
    /**
     * Abbreviation (only for when organs are created).
     */
    #[Column(type: Types::STRING)]
    private string $abbr;

    /**
     * Name (only for when organs are created).
     */
    #[Column(type: Types::STRING)]
    private string $name;

    /**
     * Purpose (only for when organs are created).
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    private ?string $purpose = null;

    /**
     * Type of the organ.
     */
    #[Column(
        type: Types::STRING,
        enumType: OrganTypes::class,
    )]
    private OrganTypes $organType;

    /**
     * References from other subdecisions to this organ.
     *
     * @var Collection<array-key, FoundationReference>
     */
    #[OneToMany(
        targetEntity: FoundationReference::class,
        mappedBy: 'foundation',
    )]
    private Collection $references;

    /**
     * Organ entry for this organ.
     */
    #[OneToOne(
        targetEntity: Organ::class,
        mappedBy: 'foundation',
    )]
    private Organ $organ;

    public function __construct()
    {
        $this->references = new ArrayCollection();
    }

    /**
     * Get the abbreviation.
     */
    public function getAbbr(): string
    {
        return $this->abbr;
    }

    /**
     * Set the abbreviation.
     */
    public function setAbbr(string $abbr): void
    {
        $this->abbr = $abbr;
    }

    /**
     * Get the name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the name.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Get the purpose.
     */
    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    /**
     * Set the purpose.
     */
    public function setPurpose(?string $purpose): void
    {
        $this->purpose = $purpose;
    }

    /**
     * Get the type.
     */
    public function getOrganType(): OrganTypes
    {
        return $this->organType;
    }

    /**
     * Set the type.
     */
    public function setOrganType(OrganTypes $organType): void
    {
        $this->organType = $organType;
    }

    /**
     * Get the references.
     *
     * @return Collection<array-key, FoundationReference>
     */
    public function getReferences(): Collection
    {
        return $this->references;
    }

    /**
     * Get the referenced organ.
     */
    public function getOrgan(): Organ
    {
        return $this->organ;
    }

    /**
     * Set the referenced organ.
     *
     * Kept in step with the owning side, so that an organ only just derived from this foundation can be found right
     * away, without having to go through the database for it.
     */
    public function setOrgan(Organ $organ): void
    {
        $this->organ = $organ;
    }

    /**
     * Forget what was derived from this subdecision, because it no longer exists.
     *
     * Leaves the property uninitialised again, which is how the rest of the code recognises that there is nothing.
     */
    public function clearOrgan(): void
    {
        unset($this->organ);
    }

    /**
     * Get a unique identifier for this foundation. It is used to distinguish between organs that share the same name
     * but are actually distinct.
     */
    public function getHash(): string
    {
        return sprintf(
            '%s-%d.%d.%d.%d',
            $this->getMeetingType()->value,
            $this->getMeetingNumber(),
            $this->getDecisionPoint(),
            $this->getDecisionNumber(),
            $this->getSequence(),
        );
    }

    /**
     * Get an array with all information.
     *
     * Mostly useful for usage with JSON.
     *
     * @return array{
     *     meeting_type: MeetingTypes,
     *     meeting_number: int,
     *     decision_point: int,
     *     decision_number: int,
     *     subdecision_sequence: int,
     *     abbr: string,
     *     name: string,
     *     organtype: OrganTypes,
     * }
     */
    public function toArray(): array
    {
        $decision = $this->getDecision();

        return [
            'meeting_type' => $decision->getMeeting()->getType(),
            'meeting_number' => $decision->getMeeting()->getNumber(),
            'decision_point' => $decision->getPoint(),
            'decision_number' => $decision->getNumber(),
            'subdecision_sequence' => $this->getSequence(),
            'abbr' => $this->getAbbr(),
            'name' => $this->getName(),
            'organtype' => $this->getOrganType(),
        ];
    }
}
