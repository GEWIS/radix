<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Frontpage;

use App\Entity\Frontpage\Poll;
use App\Repository\Frontpage\PollRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTime;

use function count;

final class PollRepositoryTest extends DatabaseTestCase
{
    public function testEveryRunningPollComesOut(): void
    {
        $running = $this->repository()->findActivePolls();

        self::assertGreaterThan(
            1,
            count($running),
            'The seed is expected to have more than one poll running at once.',
        );

        foreach ($running as $poll) {
            self::assertTrue($poll->isActive());
        }
    }

    public function testWhatClosesFirstComesFirst(): void
    {
        $previous = null;

        foreach ($this->repository()->findActivePolls() as $poll) {
            $expiryDate = $poll->getExpiryDate();
            self::assertInstanceOf(
                DateTime::class,
                $expiryDate,
            );

            if (null !== $previous) {
                self::assertGreaterThanOrEqual(
                    $previous,
                    $expiryDate,
                );
            }

            $previous = $expiryDate;
        }
    }

    public function testTheLastClosedPollIsTheOneThatClosedMostRecently(): void
    {
        $last = $this->repository()->findMostRecentlyClosed();
        self::assertInstanceOf(
            Poll::class,
            $last,
            'The seed is expected to contain a poll that has closed.',
        );
        self::assertFalse($last->isActive());

        $expiryDate = $last->getExpiryDate();
        self::assertInstanceOf(
            DateTime::class,
            $expiryDate,
        );

        foreach ($this->repository()->findAll() as $poll) {
            if (
                null === $poll->getLiveRevision()
                || $poll->isActive()
            ) {
                continue;
            }

            self::assertLessThanOrEqual(
                $expiryDate,
                $poll->getExpiryDate(),
            );
        }
    }

    private function repository(): PollRepository
    {
        return self::getContainer()->get(PollRepository::class);
    }
}
