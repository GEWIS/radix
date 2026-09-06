<?php

declare(strict_types=1);

namespace App\Entity\Activity\Enums;

use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The quick filters over a sign-up table: one list's sign-ups, or everybody on the activity side by side.
 */
enum SignupFilter: string implements TranslatableInterface
{
    case All = 'all';
    case Admitted = 'admitted';
    case Waiting = 'waiting';
    case External = 'external';
    case Multi = 'multi';
    case One = 'one';

    /**
     * The filters offered over one list.
     *
     * @return list<self>
     */
    public static function forList(bool $limited): array
    {
        return $limited
            ? [
                self::All,
                self::Admitted,
                self::Waiting,
                self::External,
                self::Multi,
            ]
            : [
                self::All,
                self::External,
                self::Multi,
            ];
    }

    /**
     * The filters offered over everybody on the activity.
     *
     * @return list<self>
     */
    public static function forPeople(): array
    {
        return [
            self::All,
            self::Multi,
            self::One,
            self::External,
            self::Waiting,
        ];
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::All => $translator->trans(
                'Everyone',
                locale: $locale,
            ),
            self::Admitted => $translator->trans(
                'Admitted',
                locale: $locale,
            ),
            self::Waiting => $translator->trans(
                'Waiting list',
                locale: $locale,
            ),
            self::External => $translator->trans(
                'Externals',
                locale: $locale,
            ),
            self::Multi => $translator->trans(
                'Also in another list',
                locale: $locale,
            ),
            self::One => $translator->trans(
                'In one list only',
                locale: $locale,
            ),
        };
    }
}
