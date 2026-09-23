<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision\Key;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Application\Traits\FormattableDateTrait;
use App\Entity\Database\SubDecision;
use App\Repository\Database\SubDecision\Key\WithdrawalRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Entity(repositoryClass: WithdrawalRepository::class)]
class Withdrawal extends SubDecision
{
    use FormattableDateTrait;

    /**
     * Reference to the granting of a keycode.
     */
    #[OneToOne(
        targetEntity: Granting::class,
        inversedBy: 'withdrawal',
    )]
    #[JoinColumn(
        name: 'r_meeting_type',
        referencedColumnName: 'meeting_type',
    )]
    #[JoinColumn(
        name: 'r_meeting_number',
        referencedColumnName: 'meeting_number',
    )]
    #[JoinColumn(
        name: 'r_decision_point',
        referencedColumnName: 'decision_point',
    )]
    #[JoinColumn(
        name: 'r_decision_number',
        referencedColumnName: 'decision_number',
    )]
    #[JoinColumn(
        name: 'r_sequence',
        referencedColumnName: 'sequence',
    )]
    public Granting $granting;

    /**
     * When the granted keycode is prematurely revoked.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $withdrawnOn;

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        return $translator->trans(
            'De sleutelcode van %GRANTEE% van GEWIS wordt ingetrokken vanaf %WITHDRAWAL%.',
            locale: $language->getLangParam(),
        );
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        $replacements = [
            '%GRANTEE%' => $this->granting->getMember()->getFullName(),
            '%WITHDRAWAL%' => $this->formatDate(
                $this->withdrawnOn,
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
