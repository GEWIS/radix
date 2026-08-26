<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision\Board;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Member;
use App\Entity\Database\NamesMember;
use App\Entity\Database\SubDecision;
use App\Entity\Database\Traits\MemberAwareTrait;
use App\Repository\Database\SubDecision\Board\CandidateRepository;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;

/**
 * One candidate the board puts forward.
 *
 * It names neither the year nor the association: the {@see Candidacy} it follows says both once, and the
 * constitutional order the candidates are put forward in is the order of the sub-decisions.
 */
#[Entity(repositoryClass: CandidateRepository::class)]
class Candidate extends SubDecision implements NamesMember
{
    use MemberAwareTrait;

    /**
     * Get the candidate.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; a candidacy always names its candidate.
        assert(null !== $this->member);

        return $this->member;
    }

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            '%MEMBER%.',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = ['%MEMBER%' => $this->getMember()->getFullName()];

        return $this->replaceContentPlaceholders(
            $this->getTranslatedTemplate(
                $translator,
                $language,
            ),
            $replacements,
        );
    }
}
