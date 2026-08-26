<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision\Board;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\SubDecision;
use App\Repository\Database\SubDecision\Board\CandidacyRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * That the board puts candidates forward for the board of an association year.
 *
 * The opening of the decision, and the only place the year is named: the candidates that follow it are
 * {@see Candidate} sub-decisions naming nothing but who stands for what.
 */
#[Entity(repositoryClass: CandidacyRepository::class)]
class Candidacy extends SubDecision
{
    /**
     * The first calendar year of the association year the candidates stand for.
     */
    #[Column(type: 'integer')]
    private int $boardYear;

    /**
     * Get the first calendar year of the association year.
     */
    public function getBoardYear(): int
    {
        return $this->boardYear;
    }

    /**
     * Set the first calendar year of the association year.
     */
    public function setBoardYear(int $boardYear): void
    {
        $this->boardYear = $boardYear;
    }

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            'Het bestuur stelt, op constitutievolgorde, de volgende kandidaten voor het bestuur %BOARD_YEAR%:',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = [
            '%BOARD_YEAR%' => sprintf(
                '%d - %d',
                $this->getBoardYear(),
                $this->getBoardYear() + 1,
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
