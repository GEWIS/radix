<?php

declare(strict_types=1);

namespace App\Entity\Career;

use App\Entity\Application\AbstractRevision;
use App\Entity\Application\AbstractRevisionComment;
use App\Entity\Application\RevisableInterface;
use App\Entity\Career\Enums\VacancyCategories;
use App\Repository\Career\VacancyRevisionRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Override;

/**
 * An immutable snapshot of a {@see Vacancy}'s revisable content for one point in its revision chain. The stable
 * {@see Vacancy} owns the slug, publication flag and package; everything that may be revised and reviewed (the
 * localised texts, the contact details, the category and the labels) is on this entity, so label changes go through
 * the review workflow like the rest of the content.
 */
#[Entity(repositoryClass: VacancyRevisionRepository::class)]
#[HasLifecycleCallbacks]
class VacancyRevision extends AbstractRevision
{
    /**
     * The vacancy this revision belongs to.
     */
    #[ManyToOne(
        targetEntity: Vacancy::class,
        inversedBy: 'revisions',
    )]
    #[JoinColumn(nullable: false)]
    public Vacancy $vacancy;

    /**
     * The revision this one supersedes (null for the first revision in the chain).
     */
    #[ManyToOne(targetEntity: self::class)]
    #[JoinColumn(nullable: true)]
    private ?VacancyRevision $previousRevision = null;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'name_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $name;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'location_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $location;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'website_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $website;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'description_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $description;

    #[OneToOne(
        targetEntity: CareerLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'attachment_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public CareerLocalisedText $attachment;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contactName = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contactPhone = null;

    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $contactEmail = null;

    /**
     * Which of the four kinds of posting this is. Jobs by default, so a blank revision is complete enough for a form
     * to bind to.
     */
    #[Column(
        type: Types::STRING,
        enumType: VacancyCategories::class,
    )]
    public VacancyCategories $category = VacancyCategories::Jobs;

    /**
     * The day the vacancy starts being shown, or null to show it from the moment it is approved.
     */
    #[Column(
        type: Types::DATE_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $startDate = null;

    /**
     * The last day the vacancy is shown. Required: a company knows when applications close before it knows anything
     * else, and a posting without a required closing day stays up after it is no longer relevant. The owning job
     * package caps it regardless, since a vacancy cannot outlive the contract it was sold under.
     *
     * The window is part of the reviewed content, so moving it goes past the committee like anything else.
     *
     * PHP-nullable so a not-yet-filled draft renders an empty field; the column stays NOT NULL and the form's NotBlank
     * constraint guarantees a value before persist, so a saved revision always has a closing day.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public ?DateTimeImmutable $endDate = null;

    /**
     * The labels of this revision of the vacancy. Each revision owns its own assignments (carried forward when a draft
     * is cloned), so label changes are staged with the revision and only become public on approval.
     *
     * @var Collection<array-key, VacancyLabel>
     */
    #[ManyToMany(
        targetEntity: VacancyLabel::class,
        inversedBy: 'revisions',
        cascade: ['persist'],
    )]
    #[JoinTable(name: 'VacancyRevisionLabelAssignment')]
    private Collection $labels;

    public function __construct()
    {
        $this->labels = new ArrayCollection();

        // The localised texts belong to the revision itself, and a form cannot bind to one that has none.
        // Doctrine does not run this when it hydrates a stored revision, so nothing is thrown away.
        $this->name = new CareerLocalisedText(
            null,
            null,
        );
        $this->location = new CareerLocalisedText(
            null,
            null,
        );
        $this->website = new CareerLocalisedText(
            null,
            null,
        );
        $this->description = new CareerLocalisedText(
            null,
            null,
        );
        $this->attachment = new CareerLocalisedText(
            null,
            null,
        );
    }

    #[Override]
    public function getRevisable(): RevisableInterface
    {
        return $this->vacancy;
    }

    /**
     * @return class-string<AbstractRevisionComment>
     */
    #[Override]
    public function getCommentClass(): string
    {
        return VacancyRevisionComment::class;
    }

    /**
     * @return Collection<array-key, VacancyLabel>
     */
    public function getLabels(): Collection
    {
        return $this->labels;
    }

    /**
     * @param VacancyLabel[] $labels
     */
    public function addLabels(array $labels): void
    {
        foreach ($labels as $label) {
            $this->addLabel($label);
        }
    }

    public function addLabel(VacancyLabel $label): void
    {
        if ($this->labels->contains($label)) {
            return;
        }

        $this->labels->add($label);
        $label->addRevision($this);
    }

    /**
     * @param VacancyLabel[] $labels
     */
    public function removeLabels(array $labels): void
    {
        foreach ($labels as $label) {
            $this->removeLabel($label);
        }
    }

    public function removeLabel(VacancyLabel $label): void
    {
        if (!$this->labels->contains($label)) {
            return;
        }

        $this->labels->removeElement($label);
        $label->removeRevision($this);
    }

    #[Override]
    public function getPreviousRevision(): ?VacancyRevision
    {
        return $this->previousRevision;
    }

    public function setPreviousRevision(?VacancyRevision $previousRevision): void
    {
        $this->previousRevision = $previousRevision;
    }

    #[Override]
    public function detachPreviousRevision(): void
    {
        $this->previousRevision = null;
    }

    /**
     * Whether today falls inside the posting window, which closes at the start of the closing day like everything
     * else that expires. A revision without a closing day has not been saved yet, so there is nothing to have fallen
     * outside of.
     */
    public function isWithinPostingWindow(): bool
    {
        $today = new DateTimeImmutable('today');

        if (
            null !== $this->startDate
            && $today < $this->startDate
        ) {
            return false;
        }

        return null === $this->endDate
            || $today < $this->endDate;
    }
}
