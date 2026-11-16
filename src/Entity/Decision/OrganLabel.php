<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Entity\Application\LabelInterface;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\RetirableTrait;
use App\Repository\Decision\OrganLabelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Override;

/**
 * A label that the revisions of a body's page are tagged with. The committee page shows it on the card, so a
 * committee is grouped by what it offers: education, career, personal development, fun, and the like.
 */
#[Entity(repositoryClass: OrganLabelRepository::class)]
class OrganLabel implements LabelInterface
{
    use IdentifiableTrait;
    use RetirableTrait;

    /**
     * The name of the label.
     */
    #[OneToOne(
        targetEntity: DecisionLocalisedText::class,
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
    public DecisionLocalisedText $name;

    /**
     * The body page revisions this label is assigned to (labels are on the revision so their changes are reviewable).
     *
     * @var Collection<array-key, OrganInformationRevision>
     */
    #[ManyToMany(
        targetEntity: OrganInformationRevision::class,
        mappedBy: 'labels',
        cascade: ['persist'],
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $revisions;

    public function __construct()
    {
        $this->revisions = new ArrayCollection();
        $this->name = new DecisionLocalisedText();
    }

    #[Override]
    public static function textClass(): string
    {
        return DecisionLocalisedText::class;
    }

    /**
     * Gets the body page revisions associated with this label.
     *
     * @return Collection<array-key, OrganInformationRevision>
     */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    #[Override]
    public function isInUse(): bool
    {
        return !$this->revisions->isEmpty();
    }

    public function addRevision(OrganInformationRevision $revision): void
    {
        if ($this->revisions->contains($revision)) {
            return;
        }

        $this->revisions->add($revision);
    }

    public function removeRevision(OrganInformationRevision $revision): void
    {
        if (!$this->revisions->contains($revision)) {
            return;
        }

        $this->revisions->removeElement($revision);
    }
}
