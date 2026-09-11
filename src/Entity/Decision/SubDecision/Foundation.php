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
    public string $abbr;

    /**
     * Name (only for when organs are created).
     */
    #[Column(type: Types::STRING)]
    public string $name;

    /**
     * Purpose (only for when organs are created).
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $purpose = null;

    /**
     * Type of the organ.
     */
    #[Column(
        type: Types::STRING,
        enumType: OrganTypes::class,
    )]
    public OrganTypes $organType;

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
    final public Organ $organ;

    public function __construct()
    {
        $this->references = new ArrayCollection();
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
            $this->sequence,
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
        $decision = $this->decision;

        return [
            'meeting_type' => $decision->meeting->type,
            'meeting_number' => $decision->meeting->number,
            'decision_point' => $decision->point,
            'decision_number' => $decision->number,
            'subdecision_sequence' => $this->sequence,
            'abbr' => $this->abbr,
            'name' => $this->name,
            'organtype' => $this->organType,
        ];
    }
}
