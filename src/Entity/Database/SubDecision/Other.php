<?php

declare(strict_types=1);

namespace App\Entity\Database\SubDecision;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\SubDecision;
use App\Repository\Database\SubDecision\OtherRepository;
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
    #[Column(type: 'text')]
    private string $contentNL;

    /** Null for the decisions recorded before the form asked for a translation. */
    #[Column(
        type: 'text',
        nullable: true,
    )]
    private ?string $contentEN = null;

    public function getContentNL(): string
    {
        return $this->contentNL;
    }

    public function setContentNL(string $contentNL): void
    {
        $this->contentNL = $contentNL;
    }

    public function getContentEN(): ?string
    {
        return $this->contentEN;
    }

    public function setContentEN(?string $contentEN): void
    {
        $this->contentEN = $contentEN;
    }

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
