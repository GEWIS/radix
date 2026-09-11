<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Repository\Database\SubDecision\AbrogationRepository;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Abrogation of an organ.
 */
#[Entity(repositoryClass: AbrogationRepository::class)]
class Abrogation extends FoundationReference
{
    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            '%ORGAN_TYPE% %ORGAN_ABBR% wordt opgeheven.',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = [
            '%ORGAN_TYPE%' => $this->foundation->organType->trans(
                $translator,
                $language->getLangParam(),
            ),
            '%ORGAN_ABBR%' => $this->foundation->abbr,
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
