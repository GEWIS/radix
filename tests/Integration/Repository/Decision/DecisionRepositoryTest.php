<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Decision;
use App\Repository\Decision\DecisionRepository;
use App\Service\Decision\DecisionSearchQueryParser;
use App\Tests\Integration\DatabaseTestCase;
use Override;

use function array_values;
use function count;
use function sprintf;

final class DecisionRepositoryTest extends DatabaseTestCase
{
    private DecisionRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(DecisionRepository::class);
    }

    /**
     * @return Decision[]
     */
    private function search(string $prompt): array
    {
        return $this->repository->search(new DecisionSearchQueryParser()->parse($prompt));
    }

    /**
     * A meeting-reference query binds the meeting type; binding it with the enum class as the DBAL type used to
     * explode with "Unknown column type" once the search page actually ran this query.
     */
    public function testSearchByMeetingReferenceFindsTheMeetingsDecisions(): void
    {
        $results = $this->search('BM 1800');

        self::assertNotEmpty($results);
        foreach ($results as $decision) {
            self::assertSame(
                MeetingTypes::BV,
                $decision->getMeeting()->getType(),
            );
            self::assertSame(
                1800,
                $decision->getMeeting()->getNumber(),
            );
        }
    }

    public function testSearchByPointReferenceNarrowsToThePoint(): void
    {
        $results = $this->search('BM 1800.2');

        self::assertNotEmpty($results);
        foreach ($results as $decision) {
            self::assertSame(
                2,
                $decision->getPoint(),
            );
        }
    }

    /**
     * A virtual meeting exists to say again what a real meeting decided. Searching for the words they share must not
     * answer with both, which is what naming the decision it repeats settles.
     */
    public function testADecisionThatRepeatsAnotherIsLeftOutOfTheTextSearch(): void
    {
        $results = $this->search('introductieweekend ter hoogte van');

        self::assertNotEmpty($results);
        foreach ($results as $decision) {
            self::assertNull(
                $decision->getCounterpart(),
                'A decision that repeats another should not answer a search the one it repeats answers.',
            );
        }
    }

    /**
     * Hidden from the results, but not out of reach: the search page folds them away under the decision they repeat,
     * which is what this answers with.
     */
    public function testTheVirtualDecisionsRepeatingAResultAreFoundAlongsideIt(): void
    {
        $results = $this->search('introductieweekend ter hoogte van');

        self::assertNotEmpty($results);

        $repeats = $this->repository->findVirtualCounterpartsOf(array_values($results));

        self::assertNotEmpty(
            $repeats,
            'The decision the seed repeats should be among the results, with its repeat alongside it.',
        );

        foreach ($repeats as $key => $decisions) {
            foreach ($decisions as $decision) {
                $counterpart = $decision->getCounterpart();

                self::assertSame(
                    MeetingTypes::VIRT,
                    $decision->getMeeting()->getType(),
                );
                self::assertNotNull($counterpart);
                self::assertSame(
                    $key,
                    DecisionRepository::key($counterpart),
                );
            }
        }
    }

    /**
     * It is still on the record and still reachable: naming it directly is not the text search this rule is about.
     */
    public function testADecisionThatRepeatsAnotherIsStillFoundByItsOwnReference(): void
    {
        $results = $this->search('Virt 1.1.1');

        self::assertCount(
            1,
            $results,
        );
        self::assertNotNull($results[0]->getCounterpart());
    }

    public function testExcludedTermsDropMatches(): void
    {
        // Every seeded decision contains "wordt"; the foundations also contain "opgericht".
        $baseline = $this->search('wordt');
        self::assertNotEmpty($baseline);

        $narrowed = $this->search('wordt -opgericht');
        self::assertNotEmpty($narrowed);
        self::assertLessThan(
            count($baseline),
            count($narrowed),
        );
        foreach ($narrowed as $decision) {
            self::assertStringNotContainsString(
                'opgericht',
                $decision->getContentNL(),
            );
        }
    }

    public function testQuotedPhraseMatchesAsAWhole(): void
    {
        self::assertNotEmpty($this->search('"wordt opgericht"'));
        self::assertEmpty($this->search('"opgericht wordt"'));
    }

    public function testTypeFilterRestrictsTextMatches(): void
    {
        $results = $this->search('type:bm wordt');

        self::assertNotEmpty($results);
        foreach ($results as $decision) {
            self::assertSame(
                MeetingTypes::BV,
                $decision->getMeeting()->getType(),
            );
        }

        self::assertEmpty($this->search('type:cm wordt'));
    }

    public function testAllBareWordsMustMatch(): void
    {
        self::assertEmpty($this->search('wordt xyzzynope'));
    }

    /**
     * A reference is written by hand, and it used to answer only when it was typed the way the register writes it:
     * `bv 1800` fell through to the text search, which finds every decision mentioning the meeting but not one of
     * the decisions the meeting took.
     */
    public function testAMeetingReferenceIsReadHoweverItWasTyped(): void
    {
        $expected = $this->references($this->search('BM 1800'));

        self::assertNotEmpty($expected);

        foreach (
            [
                'bm 1800',
                'BV 1800',
                'bv 1800',
                'Bv 1800',
            ] as $prompt
        ) {
            self::assertSame(
                $expected,
                $this->references($this->search($prompt)),
                sprintf(
                    'Searching for "%s" should answer with the decisions of that meeting.',
                    $prompt,
                ),
            );
        }
    }

    /**
     * The decisions of the meeting asked for stand ahead of the ones that only mention it, so that the result cap
     * cannot cut off the meeting itself.
     */
    public function testTheDecisionsOfTheMeetingAskedForComeFirst(): void
    {
        // The seeded annulment in BV 1804 names the decision it annuls, so it matches "BV 1801" on its text alone.
        $results = $this->references($this->search('BV 1801'));

        self::assertSame(
            [
                'BV 1801.1.1',
                'BV 1804.1.1',
            ],
            $results,
        );
    }

    public function testTheMeetingFilterNarrowsToThatMeeting(): void
    {
        self::assertSame(
            ['BV 1801.1.1'],
            $this->references($this->search('type:bm meeting:1801')),
        );

        self::assertSame(
            ['BV 1800.2.1'],
            $this->references($this->search('meeting:1800.2')),
        );
    }

    /**
     * A number without a type addresses every meeting carrying it, until `type:` says which one is meant. Without
     * that, "type:bm 1" answered with the first agenda point of the first GMM as readily as with board meeting 1.
     */
    public function testTheTypeFilterNarrowsABareNumber(): void
    {
        $results = $this->references($this->search('type:bm 1'));

        self::assertNotEmpty($results);
        self::assertSame(
            'BV 1.1.1',
            $results[0],
        );

        foreach ($this->search('type:bm 1') as $decision) {
            self::assertSame(
                MeetingTypes::BV,
                $decision->getMeeting()->getType(),
            );
        }
    }

    /**
     * Unlike a spelled-out reference, the filter is a filter: it narrows the text match instead of standing beside
     * it, so the decisions that merely mention the meeting are left out.
     */
    public function testTheMeetingFilterCombinesWithTheTextSearch(): void
    {
        self::assertSame(
            ['BV 1801.1.1'],
            $this->references($this->search('meeting:1801 begroting')),
        );

        self::assertEmpty($this->search('meeting:1801 xyzzynope'));
    }

    /**
     * The text search leaves a virtual decision out, because it repeats one taken in a real meeting. Asking for the
     * virtual meeting itself is asking for what it put on the record.
     */
    public function testTheMeetingFilterReachesAVirtualMeeting(): void
    {
        $results = $this->search('type:virt meeting:1');

        self::assertCount(
            1,
            $results,
        );
        self::assertNotNull($results[0]->getCounterpart());
    }

    /**
     * @param Decision[] $decisions
     *
     * @return list<string>
     */
    private function references(array $decisions): array
    {
        $references = [];
        foreach ($decisions as $decision) {
            $references[] = DecisionRepository::key($decision);
        }

        return $references;
    }
}
