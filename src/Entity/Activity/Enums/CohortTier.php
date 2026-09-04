<?php

declare(strict_types=1);

namespace App\Entity\Activity\Enums;

use App\Entity\Application\PriorityTierInterface;
use Override;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;

enum CohortTier: string implements PriorityTierInterface
{
    case FirstYear = 'first-year';
    case SecondYear = 'second-year';
    case Senior = 'third-year-and-above';
    case Unknown = 'unknown';

    /**
     * @return list<self>
     */
    #[Override]
    public static function defaultOrder(): array
    {
        return [
            self::FirstYear,
            self::SecondYear,
            self::Senior,
            self::Unknown,
        ];
    }

    /**
     * @return list<list<self>>
     */
    #[Override]
    public static function defaultRanks(): array
    {
        return array_map(
            static fn (self $tier): array => [$tier],
            self::defaultOrder(),
        );
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::FirstYear => $translator->trans(
                'First-year members',
                locale: $locale,
            ),
            self::SecondYear => $translator->trans(
                'Second-year members',
                locale: $locale,
            ),
            self::Senior => $translator->trans(
                'Third-year members and above',
                locale: $locale,
            ),
            self::Unknown => $translator->trans(
                'Everyone else',
                locale: $locale,
            ),
        };
    }
}
