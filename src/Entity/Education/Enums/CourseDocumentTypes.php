<?php

declare(strict_types=1);

namespace App\Entity\Education\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two kinds of course material. The backing values match the discriminator of the single table both are stored in,
 * so the two never drift apart.
 */
enum CourseDocumentTypes: string implements TranslatableInterface
{
    case Exam = 'exam';
    case Summary = 'summary';

    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::Exam => new TranslatableMessage('Exam'),
            self::Summary => new TranslatableMessage('Summary'),
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->label()->trans(
            $translator,
            $locale,
        );
    }

    public function pluralLabel(): TranslatableMessage
    {
        return match ($this) {
            self::Exam => new TranslatableMessage('Exams and answers'),
            self::Summary => new TranslatableMessage('Summaries'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Exam => 'fa-file-pen',
            self::Summary => 'fa-file-lines',
        };
    }

    public function iconClass(): string
    {
        return match ($this) {
            self::Exam => 'education-doc-icon-exam',
            self::Summary => 'education-doc-icon-summary',
        };
    }
}
