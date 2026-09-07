<?php

declare(strict_types=1);

namespace App\Entity\Activity\Enums;

use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MembershipPriorityMode: string implements TranslatableInterface
{
    case Ordering = 'ordering';
    case ReservedPlaces = 'reserved-places';

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::Ordering => $translator->trans(
                'Admit the tiers in order',
                locale: $locale,
            ),
            self::ReservedPlaces => $translator->trans(
                'Reserve a number of places per tier',
                locale: $locale,
            ),
        };
    }
}
