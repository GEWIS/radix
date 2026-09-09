<?php

declare(strict_types=1);

namespace App\Entity\Activity\Enums;

use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A placeholder in a bulk email that is replaced with a value taken from the activity, from whoever organises it, or
 * from the sign-up list the message is addressed to, rather than from an answer a recipient gave. The backing value
 * is the name written in the message, so these cases are the complete reserved vocabulary: a sign-up question named
 * after one of them is given a different token instead (see {@see \App\Util\Activity\AnnouncementPlaceholders}).
 *
 * The names match the GEWIS mailings templates in ``templates/emails/'', so an organiser who has written one of
 * those writes the same names here.
 */
enum AnnouncementPlaceholder: string implements TranslatableInterface
{
    /** The name of the activity. */
    case ActivityName = 'ACTIVITY_NAME';

    /** The name of the sign-up list the message is addressed to. */
    case SignupListName = 'SIGNUPLIST_NAME';

    /** The body organising the activity, or the board when no body does. */
    case OrganName = 'ORGAN_NAME';

    /** The abbreviation of that body. */
    case OrganAbbr = 'ORGAN_ABBR';

    /** The company organising the activity, when one does. */
    case CompanyName = 'COMPANY_NAME';

    /** Where the activity takes place. */
    case Location = 'LOCATION';

    /** What attending the activity costs. */
    case Costs = 'COSTS';

    /** When the activity begins. */
    case BeginTime = 'BEGIN_TIME';

    /** When the activity ends. */
    case EndTime = 'END_TIME';

    /**
     * Whether the value comes from one sign-up list, which limits this placeholder to a message addressed to exactly
     * one. An activity may have several sign-up lists, and a message to everybody on the activity covers all of them.
     */
    public function needsSignupList(): bool
    {
        return self::SignupListName === $this;
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::ActivityName => $translator->trans(
                'Activity',
                locale: $locale,
            ),
            self::SignupListName => $translator->trans(
                'Sign-up list',
                locale: $locale,
            ),
            self::OrganName => $translator->trans(
                'Organising body',
                locale: $locale,
            ),
            self::OrganAbbr => $translator->trans(
                'Organising body, abbreviated',
                locale: $locale,
            ),
            self::CompanyName => $translator->trans(
                'Organising company',
                locale: $locale,
            ),
            self::Location => $translator->trans(
                'Location',
                locale: $locale,
            ),
            self::Costs => $translator->trans(
                'Costs',
                locale: $locale,
            ),
            self::BeginTime => $translator->trans(
                'Start',
                locale: $locale,
            ),
            self::EndTime => $translator->trans(
                'End',
                locale: $locale,
            ),
        };
    }
}
