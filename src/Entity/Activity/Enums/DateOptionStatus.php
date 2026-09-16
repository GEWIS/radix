<?php

declare(strict_types=1);

namespace App\Entity\Activity\Enums;

use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What happened to one of the dates a body proposed.
 *
 * Exactly one date option of a proposal can be Approved, which the proposal guarantees by pointing at it through a
 * unique association rather than by counting these statuses.
 */
enum DateOptionStatus: string implements TranslatableInterface
{
    /** Still awaiting a decision. */
    case Proposed = 'proposed';

    /** The board picked this date; the proposal reserves it. */
    case Approved = 'approved';

    /** Not the date that was picked, or the whole proposal was declined. */
    case Declined = 'declined';

    /** The body withdrew its proposal before a decision. */
    case Withdrawn = 'withdrawn';

    /**
     * Whether an option in this state still blocks other proposals on the calendar.
     */
    public function isStanding(): bool
    {
        return match ($this) {
            self::Proposed,
            self::Approved => true,
            default => false,
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::Proposed => $translator->trans(
                'In line',
                locale: $locale,
            ),
            self::Approved => $translator->trans(
                'Reserved',
                locale: $locale,
            ),
            self::Declined => $translator->trans(
                'Not picked',
                locale: $locale,
            ),
            self::Withdrawn => $translator->trans(
                'Withdrawn',
                locale: $locale,
            ),
        };
    }
}
