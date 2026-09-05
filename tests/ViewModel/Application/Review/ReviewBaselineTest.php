<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Application\Review;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\ViewModel\Application\Review\ReviewBaseline;
use PHPUnit\Framework\TestCase;

final class ReviewBaselineTest extends TestCase
{
    public function testAReviewIsReadAgainstWhatIsPublishedRatherThanTheDraftBeforeIt(): void
    {
        [
            $first,
            $live,
            $sentBack, $inReview
        ] = $this->chain(4);
        $activity = $inReview->getActivity();
        $activity->setLiveRevision($live);

        $baseline = ReviewBaseline::resolve(
            $inReview,
            '',
        );

        self::assertSame(
            $live,
            $baseline->revision,
        );
        self::assertTrue($baseline->isChoice());
        self::assertSame(
            [
                2,
                3,
            ],
            [
                $baseline->choices[0]->revisionNumber,
                $baseline->choices[1]->revisionNumber,
            ],
        );
        self::assertTrue($baseline->choices[0]->live);
        self::assertTrue($baseline->choices[0]->current);
        self::assertNotSame(
            $first,
            $baseline->revision,
        );
        self::assertNotSame(
            $sentBack,
            $baseline->revision,
        );
    }

    public function testTheRevisionItWasSpawnedFromIsOfferedBesideIt(): void
    {
        [,
            $live,
            $sentBack, $inReview
        ] = $this->chain(4);
        $inReview->getActivity()->setLiveRevision($live);

        $baseline = ReviewBaseline::resolve(
            $inReview,
            ReviewBaseline::PREVIOUS,
        );

        self::assertSame(
            $sentBack,
            $baseline->revision,
        );
        self::assertTrue($baseline->choices[1]->current);
    }

    public function testWithNothingLiveTheChainIsWhatItIsReadAgainst(): void
    {
        [,
            $second, $third
        ] = $this->chain(3);

        $baseline = ReviewBaseline::resolve(
            $third,
            '',
        );

        self::assertSame(
            $second,
            $baseline->revision,
        );
        self::assertFalse($baseline->isChoice());
    }

    public function testAReEditOfTheLiveRevisionOffersNoChoice(): void
    {
        [
            $live, $inReview
        ] = $this->chain(2);
        $inReview->getActivity()->setLiveRevision($live);

        $baseline = ReviewBaseline::resolve(
            $inReview,
            '',
        );

        self::assertSame(
            $live,
            $baseline->revision,
        );
        self::assertFalse($baseline->isChoice());
        self::assertTrue($baseline->choices[0]->live);
    }

    public function testTheFirstRevisionOfAllIsReadAgainstNothing(): void
    {
        [$first] = $this->chain(1);

        $baseline = ReviewBaseline::resolve(
            $first,
            '',
        );

        self::assertNull($baseline->revision);
        self::assertSame(
            [],
            $baseline->choices,
        );
    }

    /**
     * @return list<ActivityRevision>
     */
    private function chain(int $length): array
    {
        $activity = new Activity();
        $chain = [];
        $previous = null;

        for ($number = 1; $number <= $length; ++$number) {
            $revision = new ActivityRevision();
            $revision->setRevisionNumber($number);
            $revision->setPreviousRevision($previous);
            $activity->addRevision($revision);
            $activity->setCurrentRevision($revision);

            $chain[] = $revision;
            $previous = $revision;
        }

        return $chain;
    }
}
