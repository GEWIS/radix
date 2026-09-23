<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\SubDecision;
use App\Repository\Database\SubDecision\OtherRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Entity for undefined decisions.
 */
#[Entity(repositoryClass: OtherRepository::class)]
class Other extends SubDecision
{
    #[Column(type: Types::TEXT)]
    public string $contentNL;

    /** Null for the decisions recorded before the form collected a translation. */
    #[Column(
        type: Types::TEXT,
        nullable: true,
    )]
    public ?string $contentEN = null;

    #[Override]
    protected function getTranslatedTemplate(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        throw new RuntimeException('Not implemented');
    }

    #[Override]
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
    ): string {
        // The stored content is the (statutory) Dutch text, there is nothing to translate.
        if (AppLanguages::Dutch === $language) {
            return $this->contentNL;
        }

        if (null !== $this->contentEN) {
            return $this->contentEN;
        }

        return $translator->trans(
            'If you are reading this, the secretary has not done their job.',
            locale: $language->getLangParam(),
        );
    }
}
