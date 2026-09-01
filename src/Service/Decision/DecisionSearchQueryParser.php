<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use InvalidArgumentException;

use function implode;
use function mb_substr;
use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function trim;

use const PREG_UNMATCHED_AS_NULL;

/**
 * Interprets a decision search prompt: bare words must all appear in the text, `"quoted phrases"` match as a whole,
 * a leading `-` excludes a word or phrase, `type:bm` (any meeting abbreviation) restricts the meeting type and
 * `meeting:1749` restricts the meeting, point or decision. A prompt that spells a meeting out ("GMM 214.3.1") is
 * read as a reference to it as well as as text.
 */
final class DecisionSearchQueryParser
{
    private const string MEETING_FILTER = '/^meeting:([0-9]+)(?:\.([0-9]+))?(?:\.([0-9]+))?$/iu';

    private const string TYPE_FILTER = '/^type:(\S+)$/iu';

    /**
     * A bare number addresses a meeting by itself, the way the reference under it does with a type in front.
     */
    private const string BARE_REFERENCE = '/^([0-9]+)(?:\.([0-9]+))?(?:\.([0-9]+))?$/u';

    public function parse(string $query): DecisionSearchQuery
    {
        $includeTerms = [];
        $excludeTerms = [];
        $type = null;
        $meeting = null;
        $remainderParts = [];

        preg_match_all(
            '/-?"[^"]*"|\S+/u',
            $query,
            $matches,
        );

        foreach ($matches[0] as $token) {
            $negated = str_starts_with(
                $token,
                '-',
            ) && '-' !== $token;
            $body = $negated ? mb_substr(
                $token,
                1,
            ) : $token;

            if (
                str_starts_with(
                    $body,
                    '"',
                )
            ) {
                $phrase = trim(trim(
                    $body,
                    '"',
                ));
                if ('' !== $phrase) {
                    if ($negated) {
                        $excludeTerms[] = $phrase;
                    } else {
                        $includeTerms[] = $phrase;
                    }
                }

                continue;
            }

            if (!$negated) {
                if (
                    1 === preg_match(
                        self::TYPE_FILTER,
                        $body,
                        $typeMatch,
                    )
                ) {
                    try {
                        $type = MeetingTypes::tryFromSearch($typeMatch[1]);
                        continue;
                    } catch (InvalidArgumentException) {
                        // Not a meeting type; fall through and search for the token verbatim.
                    }
                }

                if (
                    1 === preg_match(
                        self::MEETING_FILTER,
                        $body,
                        $meetingMatch,
                        PREG_UNMATCHED_AS_NULL,
                    )
                ) {
                    // The type is left to `type:`, so that neither operator says what the other one says.
                    $meeting = new MeetingReference(
                        null,
                        (int) $meetingMatch[1],
                        null === $meetingMatch[2] ? null : (int) $meetingMatch[2],
                        null === $meetingMatch[3] ? null : (int) $meetingMatch[3],
                    );

                    continue;
                }
            }

            if ($negated) {
                $excludeTerms[] = $body;
                continue;
            }

            $includeTerms[] = $body;
            $remainderParts[] = $token;
        }

        $reference = $this->reference(implode(
            ' ',
            $remainderParts,
        ));

        // "type:bm 1" is board meeting 1, not every meeting numbered 1. A reference that names a type of its own says
        // which meeting it means and keeps it.
        if (
            null !== $reference
            && null === $reference->type
        ) {
            $reference = $reference->withType($type);
        }

        return new DecisionSearchQuery(
            $includeTerms,
            $excludeTerms,
            $type,
            $meeting,
            $reference,
        );
    }

    /**
     * The meeting a prompt spells out among its words, null when it spells none out.
     *
     * Start by matching meeting type and meeting number, then we also match additional meeting points and decision
     * numbers. Both the Dutch and English abbreviation for the meeting types can be used, in whichever case they were
     * typed: a reference is written by hand, and "bv 1749" is the same meeting as "BV 1749".
     *
     * To make it usable, we also split the meeting type and meeting number match into two separate capture groups.
     * In total there are four capture groups.
     *
     * Example:
     * BV 123.456.789
     *
     * Result:
     * array(5) {
     *     [0]=> string(14) "BV 123.456.789"
     *     [1]=> string(2) "BV"
     *     [2]=> string(3) "123"
     *     [3]=> string(3) "456"
     *     [4]=> string(3) "789"
     * }
     */
    private function reference(string $remainder): ?MeetingReference
    {
        $regex = '/(?:(' . implode(
            '|',
            MeetingTypes::getSearchableStrings(),
        ) . ')'
            . ' ([0-9]+))(?:\.([0-9]+))?(?:\.([0-9]+))?/i';

        if (
            1 === preg_match(
                $regex,
                $remainder,
                $reference,
                PREG_UNMATCHED_AS_NULL,
            )
        ) {
            return new MeetingReference(
                MeetingTypes::tryFromSearch((string) $reference[1]),
                (int) $reference[2],
                null === $reference[3] ? null : (int) $reference[3],
                null === $reference[4] ? null : (int) $reference[4],
            );
        }

        if (
            1 === preg_match(
                self::BARE_REFERENCE,
                trim($remainder),
                $bare,
                PREG_UNMATCHED_AS_NULL,
            )
        ) {
            return new MeetingReference(
                null,
                (int) $bare[1],
                null === $bare[2] ? null : (int) $bare[2],
                null === $bare[3] ? null : (int) $bare[3],
            );
        }

        return null;
    }
}
