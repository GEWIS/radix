<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Member;
use App\Repository\Database\SubDecision\InstallationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Installation into organ.
 */
#[Entity(repositoryClass: InstallationRepository::class)]
class Installation extends FoundationReference
{
    /**
     * The member this decision installs.
     *
     * Declared here rather than taken from {@see \App\Entity\Database\Traits\MemberAwareTrait}: an installation is
     * the one sub-decision that names an inverse side, and naming it through an association override only works
     * because the association happens to be declared in a trait rather than on the parent class. The mapping should
     * not rest on that. The column is shared with every other sub-decision in the single table, so it stays nullable
     * here even though an installation always has a member.
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'installations',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        nullable: true,
    )]
    private ?Member $member = null;

    /**
     * Function given.
     */
    #[Column(
        enumType: InstallationFunctions::class,
    )]
    private InstallationFunctions $function;

    /**
     * Reappointment subdecisions if this installation was prolonged (can be done multiple times).
     *
     * @var Collection<array-key, Reappointment>
     */
    #[OneToMany(
        targetEntity: Reappointment::class,
        mappedBy: 'installation',
    )]
    private Collection $reappointments;

    /**
     * Discharges.
     */
    #[OneToOne(
        targetEntity: Discharge::class,
        mappedBy: 'installation',
    )]
    private ?Discharge $discharge = null;

    public function __construct()
    {
        $this->reappointments = new ArrayCollection();
    }

    /**
     * Get the member.
     *
     * @psalm-suppress InvalidNullableReturnType
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }

    /**
     * Get the function.
     */
    public function getFunction(): InstallationFunctions
    {
        return $this->function;
    }

    /**
     * Set the function.
     */
    public function setFunction(InstallationFunctions $function): void
    {
        $this->function = $function;
    }

    /**
     * Get the reappointments, if they exist.
     *
     * @return Collection<array-key, Reappointment>
     */
    public function getReappointments(): Collection
    {
        return $this->reappointments;
    }

    /**
     * Get the discharge, if it exists
     */
    public function getDischarge(): ?Discharge
    {
        return $this->discharge;
    }

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            '%MEMBER% wordt geïnstalleerd als %FUNCTION% van %ORGAN_ABBR%.',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = [
            '%MEMBER%' => $this->getMember()->getFullName(),
            '%FUNCTION%' => $this->getFunction()->trans(
                $translator,
                $language->getLangParam(),
            ),
            '%ORGAN_ABBR%' => $this->getFoundation()->getAbbr(),
        ];

        return $this->replaceContentPlaceholders(
            $this->getTranslatedTemplate(
                $translator,
                $language,
            ),
            $replacements,
        );
    }
}
