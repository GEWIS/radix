<?php

declare(strict_types=1);

namespace App\Entity\Education\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function array_merge;

/**
 * Enum for the different exam types.
 */
enum ExamTypes: string implements TranslatableInterface
{
    case Final = 'exam';
    case Interim = 'interim';
    case Answers = 'answers';
    case Other = 'other';

    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::Final => new TranslatableMessage('Final'),
            self::Interim => new TranslatableMessage('Interim'),
            self::Answers => new TranslatableMessage('Answers'),
            self::Other => new TranslatableMessage('Other'),
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

    /**
     * @return array<array-key, ExamTypes|string>
     */
    public static function values(): array
    {
        return array_merge(
            array_map(
                static fn (self $status) => $status->value,
                self::cases(),
            ),
            self::cases(),
        );
    }
}
