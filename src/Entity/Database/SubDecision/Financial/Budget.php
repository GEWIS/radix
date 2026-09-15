<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision\Financial;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Application\Traits\FormattableDateTrait;
use App\Entity\Database\Member;
use App\Entity\Database\NamesMember;
use App\Entity\Database\SubDecision;
use App\Entity\Database\Traits\MemberAwareTrait;
use App\Repository\Database\SubDecision\Financial\BudgetRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Entity(repositoryClass: BudgetRepository::class)]
class Budget extends SubDecision implements NamesMember
{
    use FormattableDateTrait;
    use MemberAwareTrait;

    /**
     * Name of the budget.
     */
    #[Column(type: 'string')]
    public string $name;

    /**
     * Version of the budget.
     */
    #[Column(
        type: 'string',
        length: 32,
    )]
    public string $version;

    /**
     * Date of the budget.
     */
    #[Column(type: 'date_immutable')]
    public DateTimeImmutable $date;

    /**
     * If the budget was approved.
     */
    #[Column(type: 'boolean')]
    public bool $approval;

    /**
     * If there were changes made.
     */
    #[Column(type: 'boolean')]
    public bool $changes;

    /**
     * Get the member.
     *
     * NOTE: Before BV 1209, there are 147 BVs that have "unknown" ("onbekend" or "?") authors for budgets and financial
     * statements. Even in the minutes of those meetings they are "unknown", as such, allow `null` to be returned here.
     *
     * @psalm-ignore-nullable-return
     */
    public function getMember(): ?Member
    {
        return $this->member;
    }

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            'De begroting %NAME% van %AUTHOR%, versie %VERSION% van %DATE% wordt %APPROVAL%%CHANGES%.',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = [
            '%NAME%' => $this->name,
            '%AUTHOR%' => $this->getMember()?->getFullName()
                ?? $translator->trans(
                    'onbekend',
                    locale: $language->getLangParam(),
                ),
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
