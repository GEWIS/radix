<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\Application\AbstractRevision;
use App\Entity\Application\AbstractRevisionComment;
use App\Entity\Application\Enums\Languages;
use App\Entity\Application\RevisableInterface;
use App\Entity\Career\Company as CompanyModel;
use App\Entity\Decision\Organ as OrganModel;
use App\Repository\Activity\ActivityRevisionRepository;
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
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Override;
use SortDirection;

use function trim;

/**
 * An immutable snapshot of an {@see Activity}'s revisable content for one point in its revision chain.
 *
 * The stable {@see Activity} owns only the identity and the (immutable) creator; everything that may be revised and
 * reviewed (the organising organ and company, the labels, the localised texts, the schedule, the category, the facility
 * flags and the sign-up lists) is on this entity.
 */
#[Entity(repositoryClass: ActivityRevisionRepository::class)]
#[HasLifecycleCallbacks]
class ActivityRevision extends AbstractRevision
{
    /**
     * The activity this revision belongs to.
     */
    #[ManyToOne(
        targetEntity: Activity::class,
        inversedBy: 'revisions',
    )]
    #[JoinColumn(nullable: false)]
    public Activity $activity;

    /**
     * The revision this one supersedes (null for the first revision in the chain).
     */
    #[ManyToOne(targetEntity: self::class)]
    #[JoinColumn(nullable: true)]
    private ?ActivityRevision $previousRevision = null;

    #[OneToOne(
        targetEntity: ActivityLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'name_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityLocalisedText $name;

    #[OneToOne(
        targetEntity: ActivityLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'location_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityLocalisedText $location;

    #[OneToOne(
        targetEntity: ActivityLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'costs_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityLocalisedText $costs;

    #[OneToOne(
        targetEntity: ActivityLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EAGER',
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'description_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ActivityLocalisedText $description;

    // PHP-nullable so a not-yet-filled draft renders an empty field; the column stays NOT NULL and the form's NotBlank
    // constraint guarantees a value before persist, so a saved revision always has a schedule.
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $beginTime = null;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public ?DateTimeImmutable $endTime = null;

    #[Column(
        type: Types::STRING,
        enumType: ActivityCategories::class,
    )]
    public ActivityCategories $category;

    #[Column(type: Types::BOOLEAN)]
    public bool $requireGEFLITST = false;

    #[Column(type: Types::BOOLEAN)]
    public bool $requireZettle = false;

    /**
     * The sign-up lists for this revision. Each revision owns its own lists (cloned from the previous revision), so
     * list changes are staged with the revision and only become public on approval; on approval, existing sign-ups
     * are migrated from the outgoing live revision's lists onto these.
     *
     * @var Collection<array-key, SignupList>
     */
    #[OneToMany(
        targetEntity: SignupList::class,
        mappedBy: 'revision',
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[OrderBy([
        'promoted' => SortDirection::Descending,
        'id' => SortDirection::Ascending,
    ])]
    private Collection $signupLists;

    /**
     * The organ organising this revision of the activity.
     */
    #[ManyToOne(targetEntity: OrganModel::class)]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: true,
    )]
    public ?OrganModel $organ = null;

    /**
     * The company organising this revision of the activity.
     */
    #[ManyToOne(targetEntity: CompanyModel::class)]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: true,
    )]
    public ?CompanyModel $company = null;

    /**
     * The labels of this revision of the activity. Each revision owns its own assignments (carried forward when a draft
     * is cloned), so label changes are staged with the revision and only become public on approval.
     *
     * @var Collection<array-key, ActivityLabel>
     */
    #[ManyToMany(
        targetEntity: ActivityLabel::class,
        inversedBy: 'revisions',
        cascade: ['persist'],
    )]
    #[JoinTable(name: 'ActivityRevisionLabelAssignment')]
    private Collection $labels;

    /**
     * The audit trail of in-place edits to this revision (who saved it, when, what changed), oldest first; appended
     * automatically on every member-driven save.
     *
     * @var Collection<array-key, ActivityRevisionEdit>
     */
    #[OneToMany(
        targetEntity: ActivityRevisionEdit::class,
        mappedBy: 'revision',
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[OrderBy(['editedAt' => SortDirection::Ascending])]
    private Collection $editHistory;

    public function __construct()
    {
        $this->signupLists = new ArrayCollection();
        $this->labels = new ArrayCollection();
        $this->editHistory = new ArrayCollection();
    }

    #[Override]
    public function getRevisable(): RevisableInterface
    {
        return $this->activity;
    }

    /**
     * @return class-string<AbstractRevisionComment>
     */
    #[Override]
    public function getCommentClass(): string
    {
        return ActivityRevisionComment::class;
    }

    /**
     * @return Collection<array-key, ActivityRevisionEdit>
     */
    public function getEditHistory(): Collection
    {
        return $this->editHistory;
    }

    /**
     * @return Collection<array-key, SignupList>
     */
    public function getSignupLists(): Collection
    {
        return $this->signupLists;
    }

    /**
     * The lists keyed by their lineage, which is how a list is told apart across revisions.
     *
     * @return array<string, SignupList>
     */
    public function getSignupListsByLineage(): array
    {
        $byLineage = [];
        foreach ($this->signupLists as $list) {
            $byLineage[$list->lineageId->toRfc4122()] = $list;
        }

        return $byLineage;
    }

    public function addSignupList(SignupList $signupList): void
    {
        if ($this->signupLists->contains($signupList)) {
            return;
        }

        $this->signupLists->add($signupList);
        $signupList->revision = $this;
    }

    public function removeSignupList(SignupList $signupList): void
    {
        $this->signupLists->removeElement($signupList);
    }

    #[Override]
    public function getPreviousRevision(): ?ActivityRevision
    {
        return $this->previousRevision;
    }

    public function setPreviousRevision(?ActivityRevision $previousRevision): void
    {
        $this->previousRevision = $previousRevision;
    }

    #[Override]
    public function detachPreviousRevision(): void
    {
        $this->previousRevision = null;
    }

    /**
     * The languages this revision is written in, read from its texts: Dutch when any of them says something in Dutch,
     * English when any says something in English or none says anything in Dutch, so a revision with nothing in it
     * yet is written in English.
     *
     * @return list<Languages>
     */
    public function languages(): array
    {
        $dutch = $this->saysSomethingIn(Languages::Dutch);
        $english = $this->saysSomethingIn(Languages::English) || !$dutch;

        $languages = [];

        if ($dutch) {
            $languages[] = Languages::Dutch;
        }

        if ($english) {
            $languages[] = Languages::English;
        }

        return $languages;
    }

    private function saysSomethingIn(Languages $language): bool
    {
        foreach (
            [
                $this->name,
                $this->location,
                $this->costs,
                $this->description,
            ] as $text
        ) {
            if ('' !== trim($text->getExactText($language) ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<array-key, ActivityLabel>
     */
    public function getLabels(): Collection
    {
        return $this->labels;
    }

    /**
     * @param ActivityLabel[] $labels
     */
    public function addLabels(array $labels): void
    {
        foreach ($labels as $label) {
            $this->addLabel($label);
        }
    }

    public function addLabel(ActivityLabel $label): void
    {
        if ($this->labels->contains($label)) {
            return;
        }

        $this->labels->add($label);
        $label->addRevision($this);
    }

    /**
     * @param ActivityLabel[] $labels
     */
    public function removeLabels(array $labels): void
    {
        foreach ($labels as $label) {
            $this->removeLabel($label);
        }
    }

    public function removeLabel(ActivityLabel $label): void
    {
        if (!$this->labels->contains($label)) {
            return;
        }

        $this->labels->removeElement($label);
        $label->removeRevision($this);
    }
}
