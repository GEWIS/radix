<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum RevisionChangeKind: string implements TranslatableInterface
{
    case Same = 'same';

    case Added = 'added';

    case Removed = 'removed';

    case Changed = 'changed';

    case Moved = 'moved';

    public function isChange(): bool
    {
        return self::Same !== $this;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Same => 'badge-tag',
            self::Added => 'badge-success',
            self::Removed => 'badge-danger',
            self::Changed => 'badge-warning',
            self::Moved => 'badge-info',
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::Same => $translator->trans(
                'Unchanged',
                locale: $locale,
            ),
            self::Added => $translator->trans(
                'New',
                locale: $locale,
            ),
            self::Removed => $translator->trans(
                'Removed',
                locale: $locale,
            ),
            self::Changed => $translator->trans(
                'Edited',
                locale: $locale,
            ),
            self::Moved => $translator->trans(
                'Moved',
                locale: $locale,
            ),
        };
    }
}
