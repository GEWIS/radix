<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Application\Traits\FormattableDateTrait;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Member;
use App\Entity\Database\NamesMember;
use App\Entity\Database\SubDecision;
use App\Entity\Database\Traits\MemberAwareTrait;
use App\Repository\Database\SubDecision\OrganRegulationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;
use ValueError;

#[Entity(repositoryClass: OrganRegulationRepository::class)]
class OrganRegulation extends SubDecision implements NamesMember
{
    use FormattableDateTrait;
    use MemberAwareTrait;

    /**
     * Abbreviation of the organ.
     */
    #[Column(type: Types::STRING)]
    public string $abbr;

    /**
     * Type of the organ.
     */
    #[Column(
        enumType: OrganTypes::class,
    )]
    public OrganTypes $organType;

    /**
     * Version of the regulation.
     */
    #[Column(
        type: Types::STRING,
        length: 32,
    )]
    public string $version;

    /**
     * Date of the regulation.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $date;

    /**
     * If the regulation was approved.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $approval;

    /**
     * If there were changes made.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $changes;

    /**
     * Get the member.
     *
     * @psalm-suppress InvalidNullableReturnType
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            'Het %DOCUMENTTYPE% van %NAME% door %AUTHOR%, versie %VERSION% van %DATE% wordt %APPROVAL%%CHANGES%.',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        if (
            OrganTypes::Committee === $this->organType
            || OrganTypes::KCC === $this->organType
        ) {
            $documentType = $translator->trans(
                'commissiereglement',
                locale: $language->getLangParam(),
            );
        } elseif (OrganTypes::Fraternity === $this->organType) {
            $documentType = $translator->trans(
                'dispuutsreglement',
                locale: $language->getLangParam(),
            );
        } else {
            throw new ValueError();
        }

        $replacements = [
            '%NAME%' => $this->abbr,
            '%AUTHOR%' => $this->getMember()->getFullName(),
            '%DOCUMENTTYPE%' => $documentType,
            '%VERSION%' => $this->version,
            '%DATE%' => $this->formatDate(
                $this->date,
                $language,
            ),
            '%APPROVAL%' => $this->approval
                ? $translator->trans(
                    'goedgekeurd',
                    locale: $language->getLangParam(),
                )
                : $translator->trans(
                    'afgekeurd',
                    locale: $language->getLangParam(),
                ),
            '%CHANGES%' => $this->approval && $this->changes
                ? $translator->trans(
                    ' met genoemde wijzigingen',
                    locale: $language->getLangParam(),
                )
                : '',
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
