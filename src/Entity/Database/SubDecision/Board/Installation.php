<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision\Board;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Application\Traits\FormattableDateTrait;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Member;
use App\Entity\Database\NamesMember;
use App\Entity\Database\SubDecision;
use App\Entity\Database\Traits\MemberAwareTrait;
use App\Repository\Database\SubDecision\Board\InstallationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToOne;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Installation as board member.
 */
#[Entity(repositoryClass: InstallationRepository::class)]
class Installation extends SubDecision implements NamesMember
{
    use FormattableDateTrait;
    use MemberAwareTrait;

    /**
     * Function given.
     */
    #[Column(
        enumType: BoardFunctions::class,
    )]
    public BoardFunctions $function;

    /**
     * The date at which the installation is in effect.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $date;

    /**
     * Discharge.
     */
    #[OneToOne(
        targetEntity: Discharge::class,
        mappedBy: 'installation',
    )]
    private ?Discharge $discharge = null;

    /**
     * Release.
     */
    #[OneToOne(
        targetEntity: Release::class,
        mappedBy: 'installation',
    )]
    private ?Release $release = null;

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
            '%MEMBER% wordt per %DATE% geïnstalleerd als %FUNCTION% der s.v. GEWIS.',
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
            '%DATE%' => $this->formatDate(
                $this->date,
                $language,
            ),
            '%FUNCTION%' => $this->function->trans(
                $translator,
                $language->getLangParam(),
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
