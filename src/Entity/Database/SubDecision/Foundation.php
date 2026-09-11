<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\SubDecision;
use App\Repository\Database\SubDecision\FoundationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToMany;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Foundation of an organ.
 */
#[Entity(repositoryClass: FoundationRepository::class)]
class Foundation extends SubDecision
{
    /**
     * Abbreviation (only for when organs are created)
     */
    #[Column(type: 'string')]
    public string $abbr;

    /**
     * Name (only for when organs are created)
     */
    #[Column(type: 'string')]
    public string $name;

    /**
     * Purpose (only for when organs are created)
     */
    #[Column(
        type: 'string',
        nullable: true,
    )]
    private ?string $purpose = null;

    /**
     * Type of the organ.
     */
    #[Column(
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

    public function __construct()
    {
        $this->references = new ArrayCollection();
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
    public function setPurpose(string $purpose): void
    {
        $this->purpose = $purpose;
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

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        if (OrganTypes::SC !== $this->organType) {
            return $translator->trans(
                '%ORGAN_TYPE% %ORGAN_NAME% met afkorting %ORGAN_ABBR% wordt opgericht.',
                locale: $language->getLangParam(),
            );
        }

        return $translator->trans(
            'De stemcommissie voor %ORGAN_PURPOSE% van de %MEETING_NUMBER%e %MEETING_TYPE% met afkorting %ORGAN_ABBR% wordt opgericht.', // phpcs:ignore -- user-visible strings should not be split
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = [
            '%ORGAN_ABBR%' => $this->abbr,
        ];

        if (OrganTypes::SC !== $this->organType) {
            $replacements += [
                '%ORGAN_TYPE%' => $this->organType->trans(
                    $translator,
                    $language->getLangParam(),
                ),
                '%ORGAN_NAME%' => $this->name,
            ];
        } else {
            $replacements += [
                '%MEETING_TYPE%' => $this->getMeetingType()->trans(
                    $translator,
                    $language->getLangParam(),
                ),
                '%MEETING_NUMBER%' => $this->getMeetingNumber(),
                '%ORGAN_PURPOSE%' => $this->getPurpose(),
            ];
        }

        /** @psalm-suppress InvalidArgument */
        return $this->replaceContentPlaceholders(
            $this->getTranslatedTemplate(
                $translator,
                $language,
            ),
            $replacements,
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
     *     name: string,
     *     abbr: string,
     *     purpose: string|null,
     *     organtype: OrganTypes,
     * }
     */
    public function toArray(): array
    {
        $decision = $this->decision;

        return [
            'meeting_type' => $decision->meeting->type,
            'meeting_number' => $decision->meeting->getNumber(),
            'decision_point' => $decision->point,
            'decision_number' => $decision->number,
            'subdecision_sequence' => $this->sequence,
            'name' => $this->name,
            'abbr' => $this->abbr,
            'purpose' => $this->getPurpose(),
            'organtype' => $this->organType,
        ];
    }
}
