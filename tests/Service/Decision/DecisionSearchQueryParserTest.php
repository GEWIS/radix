<?php

declare(strict_types=1);

namespace App\Tests\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Service\Decision\DecisionSearchQueryParser;
use App\Service\Decision\MeetingReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecisionSearchQueryParserTest extends TestCase
{
    /**
     * @return array<string, array{
     *     0: string,
     *     1: list<string>,
     *     2: list<string>,
     *     3: ?MeetingTypes,
     *     4: ?MeetingReference,
     *     5: ?MeetingReference,
     * }>
     */
    public static function promptProvider(): array
    {
        // prompt, include terms, exclude terms, type, meeting filter, reference
        return [
            'bare words' => [
                'kerstboom borrel',
                [
                    'kerstboom',
                    'borrel',
                ],
                [],
                null,
                null,
                null,
            ],
            'quoted phrase' => [
                '"financieel jaarverslag"',
                ['financieel jaarverslag'],
                [],
                null,
                null,
                null,
            ],
            'excluded word' => [
                'begroting -afrekening',
                ['begroting'],
                ['afrekening'],
                null,
                null,
                null,
            ],
            'excluded phrase' => [
                '-"besluit tot decharge"',
                [],
                ['besluit tot decharge'],
                null,
                null,
                null,
            ],
            'member-facing type filter' => [
                'type:bm Example',
                ['Example'],
                [],
                MeetingTypes::BV,
                null,
                null,
            ],
            'internal type filter' => [
                'type:ALV borrel',
                ['borrel'],
                [],
                MeetingTypes::ALV,
                null,
                null,
            ],
            'unknown type keyword stays text' => [
                'type:x86',
                ['type:x86'],
                [],
                null,
                null,
                null,
            ],
            'meeting reference is read from the words' => [
                'BM 1805.3.1',
                [
                    'BM',
                    '1805.3.1',
                ],
                [],
                null,
                null,
                new MeetingReference(
                    MeetingTypes::BV,
                    1805,
                    3,
                    1,
                ),
            ],
            'a reference is read however it was typed' => [
                'bv 1749',
                [
                    'bv',
                    '1749',
                ],
                [],
                null,
                null,
                new MeetingReference(
                    MeetingTypes::BV,
                    1749,
                ),
            ],
            'a bare number addresses a meeting' => [
                '1805',
                ['1805'],
                [],
                null,
                null,
                new MeetingReference(
                    null,
                    1805,
                ),
            ],
            'a bare number takes the type from the type filter' => [
                'type:bm 1',
                ['1'],
                [],
                MeetingTypes::BV,
                null,
                new MeetingReference(
                    MeetingTypes::BV,
                    1,
                ),
            ],
            'a reference keeps the type it names itself' => [
                'type:bm GMM 214',
                [
                    'GMM',
                    '214',
                ],
                [],
                MeetingTypes::BV,
                null,
                new MeetingReference(
                    MeetingTypes::ALV,
                    214,
                ),
            ],
            'meeting filter' => [
                'meeting:1805',
                [],
                [],
                null,
                new MeetingReference(
                    null,
                    1805,
                ),
                null,
            ],
            'meeting filter down to the decision' => [
                'type:bm meeting:1805.3.1',
                [],
                [],
                MeetingTypes::BV,
                new MeetingReference(
                    null,
                    1805,
                    3,
                    1,
                ),
                null,
            ],
            'the meeting filter is not a word to search for' => [
                'meeting:1805 begroting',
                ['begroting'],
                [],
                null,
                new MeetingReference(
                    null,
                    1805,
                ),
                null,
            ],
            'unknown meeting keyword stays text' => [
                'meeting:soon',
                ['meeting:soon'],
                [],
                null,
                null,
                null,
            ],
            'lone dash is a term' => [
                '-',
                ['-'],
                [],
                null,
                null,
                null,
            ],
        ];
    }

    /**
     * @param list<string> $includeTerms
     * @param list<string> $excludeTerms
     */
    #[DataProvider('promptProvider')]
    public function testParse(
        string $prompt,
        array $includeTerms,
        array $excludeTerms,
        ?MeetingTypes $type,
        ?MeetingReference $meeting,
        ?MeetingReference $reference,
    ): void {
        $query = new DecisionSearchQueryParser()->parse($prompt);

        self::assertSame(
            $includeTerms,
            $query->includeTerms,
        );
        self::assertSame(
            $excludeTerms,
            $query->excludeTerms,
        );
        self::assertSame(
            $type,
            $query->type,
        );
        self::assertEquals(
            $meeting,
            $query->meeting,
        );
        self::assertEquals(
            $reference,
            $query->reference,
        );
    }

    /**
     * What `type:` and `meeting:` say together is the same meeting a spelled-out reference says on its own.
     */
    public function testTheNamedMeetingTakesTheTypeFromTheTypeFilter(): void
    {
        self::assertEquals(
            new MeetingReference(
                MeetingTypes::BV,
                1805,
                3,
            ),
            new DecisionSearchQueryParser()->parse('type:bm meeting:1805.3')->namedMeeting(),
        );
    }

    public function testEmptyPromptIsEmpty(): void
    {
        self::assertTrue(new DecisionSearchQueryParser()->parse('')->isEmpty());
        self::assertFalse(new DecisionSearchQueryParser()->parse('type:bm')->isEmpty());
        self::assertFalse(new DecisionSearchQueryParser()->parse('meeting:1805')->isEmpty());
    }
}
