<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Repository\Database\SubDecision\ContinuationRepository;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The continuation of a body.
 *
 * A body that is continued carries on under the foundation it has had since it was established rather than being
 * founded again, which is why this refers to that foundation. Fraternities are the ones this is said of every year;
 * it is not restricted to them.
 */
#[Entity(repositoryClass: ContinuationRepository::class)]
class Continuation extends FoundationReference
{
    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            '%ORGAN_TYPE% %ORGAN_ABBR% mag blijven voortbestaan.',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = [
            '%ORGAN_TYPE%' => $this->getFoundation()->getOrganType()->trans(
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
