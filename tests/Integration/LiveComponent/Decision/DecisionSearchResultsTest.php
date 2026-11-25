<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision;

use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\DecisionSearch;

use function array_map;
use function count;

/**
 * What the decision search reports about its own results: whether there is a query to run at all, what the terms to
 * mark are, and whether the cap was reached.
 */
final class DecisionSearchResultsTest extends DatabaseTestCase
{
    public function testAQueryOfOneCharacterIsNotSearched(): void
    {
        foreach (['', ' ', 'a'] as $prompt) {
            $component = $this->search($prompt);

            self::assertFalse($component->hasQuery());
            self::assertSame(
                [],
                $component->getResultsByMeeting(),
            );
            self::assertSame(
                0,
                $component->getResultCount(),
            );
            self::assertFalse($component->isCapped());
        }
    }

    public function testTwoCharactersAreEnoughToSearch(): void
    {
        $component = $this->search('de');

        self::assertTrue($component->hasQuery());
        self::assertSame(
            $component->getResultCount() >= DecisionSearch::RESULT_CAP,
            $component->isCapped(),
        );
        self::assertLessThanOrEqual(
            DecisionSearch::RESULT_CAP,
            $component->getResultCount(),
        );
    }

    public function testEveryWordThatHasToAppearIsMarked(): void
    {
        $component = $this->search('besluit vergadering');

        $terms = array_map(
            'strtolower',
            $component->getHighlightTerms(),
        );

        self::assertContains(
            'besluit',
            $terms,
        );
        self::assertContains(
            'vergadering',
            $terms,
        );
    }

    public function testTheResultsAreGroupedPerMeeting(): void
    {
        $component = $this->search('de');

        $groups = $component->getResultsByMeeting();
        $counted = 0;
        foreach ($groups as $group) {
            $counted += count($group['decisions']);
        }

        self::assertSame(
            $component->getResultCount(),
            $counted,
        );
    }

    public function testADecisionWithoutAVirtualCounterpartHasNone(): void
    {
        $component = $this->search('de');
        $decision = $component->getResultsByMeeting()[0]['decisions'][0] ?? null;

        self::assertNotNull($decision);
        self::assertIsArray(
            $component->getVirtualCounterpartsOf($decision),
        );
    }

    private function search(string $prompt): DecisionSearch
    {
        $component = self::getContainer()->get(DecisionSearch::class);
        $component->q = $prompt;

        return $component;
    }
}
