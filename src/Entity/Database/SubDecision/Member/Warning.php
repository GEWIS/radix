<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision\Member;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Member;
use App\Entity\Database\NamesMember;
use App\Entity\Database\SubDecision;
use App\Entity\Database\Traits\MemberAwareTrait;
use App\Repository\Database\SubDecision\Member\WarningRepository;
use Doctrine\ORM\Mapping\Entity;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;

/**
 * An official warning handed to a member by the board.
 */
#[Entity(repositoryClass: WarningRepository::class)]
class Warning extends SubDecision implements NamesMember
{
    use MemberAwareTrait;

    /**
     * Get the member who is warned.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; a warning always names the member it is
        // handed to.
        assert(null !== $this->member);

        return $this->member;
    }

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            'Het bestuur deelt een officiële waarschuwing uit aan %MEMBER%.',
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
