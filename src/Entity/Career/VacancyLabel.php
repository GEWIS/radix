<?php

declare(strict_types=1);

namespace App\Entity\Career;

use App\Entity\Application\LabelInterface;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\RetirableTrait;
use App\Repository\Career\VacancyLabelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Override;

/**
 * Vacancy Label model.
 *
 * @phpstan-type VacancyLabelArrayType = array{
 *     id: ?int,
 *     name: ?string,
 *     nameEn: ?string,
 * }
 */
#[Entity(repositoryClass: VacancyLabelRepository::class)]
class VacancyLabel implements LabelInterface
{
    use IdentifiableTrait;
    use RetirableTrait;

    /**
     * The name of the label.
     */
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

    /**
     * The vacancy revisions this Label is assigned to (labels are on the revision so their changes are reviewable).
     *
     * @var Collection<array-key, VacancyRevision>
     */
    #[ManyToMany(
        targetEntity: VacancyRevision::class,
        mappedBy: 'labels',
        cascade: ['persist'],
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $revisions;

    public function __construct()
    {
        $this->revisions = new ArrayCollection();
        $this->name = new CareerLocalisedText();
    }

    #[Override]
    public static function textClass(): string
    {
        return CareerLocalisedText::class;
    }

    /**
     * Gets the vacancy revisions associated with this label.
     *
     * @return Collection<array-key, VacancyRevision>
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

    public function addRevision(VacancyRevision $revision): void
    {
        if ($this->revisions->contains($revision)) {
            return;
        }

        $this->revisions->add($revision);
    }

    public function removeRevision(VacancyRevision $revision): void
    {
        if (!$this->revisions->contains($revision)) {
            return;
        }

        $this->revisions->removeElement($revision);
    }

    /**
     * @return VacancyLabelArrayType
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name->getValueNL(),
            'nameEn' => $this->name->getValueEN(),
        ];
    }
}
