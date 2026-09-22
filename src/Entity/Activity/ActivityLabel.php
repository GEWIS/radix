<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Application\LabelInterface;
use App\Entity\Application\LocalisedText as LocalisedTextModel;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\RetirableTrait;
use App\Repository\Activity\ActivityLabelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Override;

/**
 * Activity Label model.
 *
 * @phpstan-type ActivityLabelArrayType = array{
 *     id: ?int,
 *     name: ?string,
 *     nameEn: ?string,
 * }
 * @phpstan-import-type LocalisedTextGdprArrayType from LocalisedTextModel as ImportedLocalisedTextGdprArrayType
 * @phpstan-type ActivityLabelGdprArrayType = array{
 *     id: ?int,
 *     name: ImportedLocalisedTextGdprArrayType,
 * }
 */
#[Entity(repositoryClass: ActivityLabelRepository::class)]
class ActivityLabel implements LabelInterface
{
    use IdentifiableTrait;
    use RetirableTrait;

    /**
     * The activity revisions this Label is assigned to (labels are on the revision so their changes are reviewable).
     *
     * @var Collection<array-key, ActivityRevision>
     */
    #[ManyToMany(
        targetEntity: ActivityRevision::class,
        mappedBy: 'labels',
        cascade: ['persist'],
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $revisions;

    /**
     * Name for the Label.
     */
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

    public function __construct()
    {
        $this->revisions = new ArrayCollection();
        $this->name = new ActivityLocalisedText();
    }

    #[Override]
    public static function textClass(): string
    {
        return ActivityLocalisedText::class;
    }

    public function addRevision(ActivityRevision $revision): void
    {
        if ($this->revisions->contains($revision)) {
            return;
        }

        $this->revisions->add($revision);
    }

    public function removeRevision(ActivityRevision $revision): void
    {
        if (!$this->revisions->contains($revision)) {
            return;
        }

        $this->revisions->removeElement($revision);
    }

    #[Override]
    public function isInUse(): bool
    {
        return !$this->revisions->isEmpty();
    }

    /**
     * @return ActivityLabelArrayType
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name->getValueNL(),
            'nameEn' => $this->name->getValueEN(),
        ];
    }

    /**
     * @return ActivityLabelGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name->toGdprArray(),
        ];
    }
}
