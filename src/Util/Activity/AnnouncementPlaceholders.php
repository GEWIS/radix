<?php

declare(strict_types=1);

namespace App\Util\Activity;

use App\Entity\Activity\Activity;
use App\Entity\Activity\Enums\AnnouncementPlaceholder;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupField;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\Languages;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function array_keys;
use function in_array;
use function mb_strtoupper;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;
use function trim;

/**
 * The placeholders an organiser may write in a bulk email about an activity, written as ``{{COMPANY_NAME}}''.
 *
 * A placeholder is replaced from one of three sources, and which sources are available follows from who the message
 * is addressed to:
 *
 * - The **activity** ({@see AnnouncementPlaceholder}) gives every recipient the same value and is always available.
 * - The **sign-up list** is available only when the message is addressed to exactly one. An activity may have
 *   several sign-up lists, and a message to everybody on the activity covers all of them, so there is no single
 *   value.
 * - A **question** of that sign-up list is replaced per recipient with the answer that recipient gave, which lets
 *   one message include a value that differs for each of them. It is available under the same condition and for the
 *   same reason: two sign-up lists can ask a question of the same name and mean different things by it.
 *
 * A question's token is derived from its English name rather than stored, which keeps it readable in the composer
 * and identical whichever language the organiser writes in. Two things follow. Every case of
 * {@see AnnouncementPlaceholder} is reserved, whether or not the message being written can replace it, so a question
 * named "Location" is given a different token instead of replacing the activity's; and renaming a question changes
 * its token, which is why a message using a token that nothing replaces is refused before it is sent rather than
 * sent with nothing in its place ({@see self::unknownIn()}).
 */
final class AnnouncementPlaceholders
{
    /**
     * A placeholder as it is written. Spaces inside the braces are accepted because an organiser typing one by hand
     * writes them; the token is the upper-case name.
     *
     * Backslashes are accepted as well, and read as nothing. The composer is a Markdown editor and an underscore is
     * emphasis in Markdown, so a placeholder inserted there is stored as `{{ACTIVITY\_NAME}}`. While this pattern
     * refused those, every placeholder written in the composer was sent unreplaced, and silently:
     * {@see self::unknownIn()} reads the same pattern, so it saw nothing to report either.
     */
    private const string PATTERN = '/\{\{\s*([A-Z0-9_\\\\]+)\s*\}\}/';

    /**
     * The placeholders taken from the activity, and from the sign-up list when the message is addressed to one,
     * against the label the composer shows for each.
     *
     * @return array<string, string> token => the label shown in the composer
     */
    public static function aboutFor(
        ?SignupList $signupList,
        TranslatorInterface $translator,
    ): array {
        $about = [];
        foreach (AnnouncementPlaceholder::cases() as $placeholder) {
            if (
                $placeholder->needsSignupList()
                && null === $signupList
            ) {
                continue;
            }

            $about[$placeholder->value] = $placeholder->trans($translator);
        }

        return $about;
    }

    /**
     * The value each of those is replaced with here. A detail the activity does not have, an organising company
     * above all, is an empty string rather than a missing key, so the rest of the sentence is unaffected.
     *
     * @return array<string, string> token => the replacement
     */
    public static function aboutValuesFor(
        Activity $activity,
        ?SignupList $signupList,
        Languages $language,
    ): array {
        $values = [];
        foreach (AnnouncementPlaceholder::cases() as $placeholder) {
            if (
                $placeholder->needsSignupList()
                && null === $signupList
            ) {
                continue;
            }

            $values[$placeholder->value] = self::valueOf(
                $placeholder,
                $activity,
                $signupList,
                $language,
            );
        }

        return $values;
    }

    /**
     * The placeholders taken from a sign-up list's own questions, against the name of the question each one is
     * derived from, in the order the questions are asked.
     *
     * @return array<string, string> token => the question's name
     */
    public static function questionsOf(
        SignupList $signupList,
        Languages $language,
    ): array {
        $questions = [];
        foreach (self::tokens($signupList) as [$field, $token]) {
            $questions[$token] = $field->getName()->getText($language) ?? '';
        }

        return $questions;
    }

    /**
     * The value each question placeholder is replaced with for one sign-up: the answer that person gave, or an empty
     * string where they did not answer. Answers are read as the sign-ups page reads them, so a yes/no answer and a
     * chosen option are replaced with words rather than with what is stored.
     *
     * @return array<string, string> token => the answer
     */
    public static function answersOf(
        Signup $signup,
        TranslatorInterface $translator,
        Languages $language,
    ): array {
        $answers = [];
        foreach (self::tokens($signup->getSignupList()) as [$field, $token]) {
            $answers[$token] = $signup->displayValueForField(
                $field,
                $translator,
                $language,
            );
        }

        return $answers;
    }

    /**
     * The text with every placeholder replaced. A placeholder the map does not contain is left exactly as it was
     * written: the composer refuses to send a message using one, so this is not reached in normal use.
     *
     * @param array<string, string> $values
     */
    public static function apply(
        string $text,
        array $values,
    ): string {
        return preg_replace_callback(
            self::PATTERN,
            /** @param string[] $match */
            static fn (array $match): string => $values[self::tokenOf($match[1])] ?? $match[0],
            $text,
        ) ?? $text;
    }

    /**
     * The placeholders used in a text that nothing available replaces, without repeats and in the order they were
     * written. A mistyped name, or a message composed for one sign-up list and then addressed to everybody on the
     * activity; either way the recipients would be sent a sentence with a value missing.
     *
     * @param array<string, string> $offered
     *
     * @return list<string>
     */
    public static function unknownIn(
        string $text,
        array $offered,
    ): array {
        preg_match_all(
            self::PATTERN,
            $text,
            $matches,
        );

        $known = array_keys($offered);
        $unknown = [];
        foreach ($matches[1] as $written) {
            $token = self::tokenOf($written);
            if (
                in_array(
                    $token,
                    $known,
                    true,
                )
                || in_array(
                    $token,
                    $unknown,
                    true,
                )
            ) {
                continue;
            }

            $unknown[] = $token;
        }

        return $unknown;
    }

    /**
     * The name a placeholder was written with, as the application spells it: whatever Markdown escaping the composer
     * added is not part of it.
     */
    private static function tokenOf(string $written): string
    {
        return str_replace(
            '\\',
            '',
            $written,
        );
    }

    /**
     * A match rather than a method on the enum, so that adding a case does not compile until it has been given a
     * value here as well.
     */
    private static function valueOf(
        AnnouncementPlaceholder $placeholder,
        Activity $activity,
        ?SignupList $signupList,
        Languages $language,
    ): string {
        return match ($placeholder) {
            AnnouncementPlaceholder::ActivityName => $activity->getName()->getText($language) ?? '',
            AnnouncementPlaceholder::SignupListName => $signupList?->getName()->getText($language) ?? '',
            // An activity without a body of its own is the board's, which is what the option calendar's own email
            // states for a proposal without one. There is nothing to abbreviate, so both use the same text.
            AnnouncementPlaceholder::OrganName => $activity->getOrgan()?->getName() ?? 'the board',
            AnnouncementPlaceholder::OrganAbbr => $activity->getOrgan()?->getAbbr() ?? 'the board',
            AnnouncementPlaceholder::CompanyName => $activity->getCompany()?->getName() ?? '',
            AnnouncementPlaceholder::Location => $activity->getLocation()->getText($language) ?? '',
            AnnouncementPlaceholder::Costs => $activity->getCosts()->getText($language) ?? '',
            AnnouncementPlaceholder::BeginTime => $activity->getBeginTime()->format('j F Y, H:i'),
            AnnouncementPlaceholder::EndTime => $activity->getEndTime()->format('j F Y, H:i'),
        };
    }

    /**
     * Every question of a sign-up list with the token derived from it, in the order the questions are asked.
     *
     * @return list<array{SignupField, string}>
     */
    private static function tokens(SignupList $signupList): array
    {
        $tokens = [];
        // Seeded with every name the application has already reserved, so a question named after one of them is
        // given a different token instead of replacing it.
        $taken = array_column(
            AnnouncementPlaceholder::cases(),
            'value',
        );
        $position = 0;

        foreach ($signupList->getFields() as $field) {
            ++$position;
            $token = trim(
                (string) preg_replace(
                    '/[^A-Z0-9]+/',
                    '_',
                    mb_strtoupper($field->getName()->getText(Languages::English) ?? ''),
                ),
                '_',
            );

            // A question whose name this leaves nothing of still needs a token.
            if ('' === $token) {
                $token = 'QUESTION';
            }

            // Two questions with the same name, and a question named after a reserved name, both need a number to
            // distinguish them. Counting up from the question's own position keeps the number recognisable when
            // only one question needs one.
            $unique = $token;
            $suffix = $position;
            while (
                in_array(
                    $unique,
                    $taken,
                    true,
                )
            ) {
                $unique = $token . '_' . $suffix;
                ++$suffix;
            }

            $taken[] = $unique;
            $tokens[] = [
                $field,
                $unique,
            ];
        }

        return $tokens;
    }
}
