<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision\Member;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Application\Traits\FormattableDateTrait;
use App\Entity\Database\Member;
use App\Entity\Database\SubDecision;
use App\Entity\Database\Traits\MemberAwareTrait;
use App\Repository\Database\SubDecision\Member\SuspensionRepository;
use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;

/**
 * The suspension of a member, for a period that both ends are part of.
 */
#[Entity(repositoryClass: SuspensionRepository::class)]
class Suspension extends SubDecision
{
    use FormattableDateTrait;
    use MemberAwareTrait;

    /**
     * The first day of the suspension.
     *
     * Named `since` rather than `from`, which is a reserved word in both of the databases this table lives in and
     * would have to be quoted everywhere it is read.
     */
    #[Column(type: 'date')]
    private DateTime $since;

    /**
     * The last day of the suspension, which is part of it.
     */
    #[Column(type: 'date')]
    private DateTime $until;

    /**
     * Get the member who is suspended.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; a suspension always names the member it is
        // handed to.
        assert(null !== $this->member);

        return $this->member;
    }

    /**
     * Get the first day of the suspension.
     */
    public function getSince(): DateTime
    {
        return $this->since;
    }

    /**
     * Set the first day of the suspension.
     */
    public function setSince(DateTime $since): void
    {
        $this->since = $since;
    }

    /**
     * Get the last day of the suspension.
     */
    public function getUntil(): DateTime
    {
        return $this->until;
    }

    /**
     * Set the last day of the suspension.
     */
    public function setUntil(DateTime $until): void
    {
        $this->until = $until;
    }

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            '%MEMBER% wordt geschorst van %SINCE% tot en met %UNTIL%.',
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
            '%SINCE%' => $this->formatDate(
                $this->getSince(),
                $language,
            ),
            '%UNTIL%' => $this->formatDate(
                $this->getUntil(),
                $language,
            ),
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
