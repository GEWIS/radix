<?php

declare(strict_types=1);

namespace App\Tests\Service\Application;

use App\Service\Application\ScheduledRunRecorder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

use function hash;
use function preg_replace;
use function substr;

/**
 * The dashboard can only report a task as "last ran at" if what the worker wrote is what it reads back, under the same
 * key. The key is derived from a command line, which is full of characters a cache key may not contain.
 */
final class ScheduledRunRecorderTest extends TestCase
{
    public function testARecordedRunComesBackAsTheSameMoment(): void
    {
        $recorder = $this->recorder();
        $at = new DateTimeImmutable('2026-03-04 10:11:12');

        $recorder->record(
            'app:some:command',
            $at,
        );

        self::assertSame(
            $at->getTimestamp(),
            $recorder->lastRun('app:some:command')?->getTimestamp(),
        );
    }

    public function testACommandThatNeverRanHasNoLastRun(): void
    {
        self::assertNull($this->recorder()->lastRun('app:never:ran'));
    }

    /**
     * The first read starts the clock for every task that has never run, so a fresh deployment is not reported as
     * having been late since 1970. Once set, it does not move.
     */
    public function testSinceIsSetOnceAndThenSticks(): void
    {
        $cache = new ArrayAdapter();

        $first = new ScheduledRunRecorder(
            $cache,
            new MockClock(new DateTimeImmutable('@1000')),
        );
        self::assertSame(
            1000,
            $first->since()->getTimestamp(),
        );

        $later = new ScheduledRunRecorder(
            $cache,
            new MockClock(new DateTimeImmutable('@5000')),
        );
        self::assertSame(
            1000,
            $later->since()->getTimestamp(),
        );
    }

    public function testRecordingAlsoStartsTheClock(): void
    {
        $recorder = $this->recorder(new DateTimeImmutable('@2000'));

        $recorder->record(
            'app:some:command',
            new DateTimeImmutable('@3000'),
        );

        self::assertSame(
            2000,
            $recorder->since()->getTimestamp(),
        );
    }

    /**
     * Anything that is not the integer this writes is a cache written to by something else; guessing at it would
     * report a run that never happened.
     */
    public function testAValueOfAnotherTypeIsTreatedAsAbsent(): void
    {
        $cache = new ArrayAdapter();
        $recorder = new ScheduledRunRecorder(
            $cache,
            new MockClock(new DateTimeImmutable('@0')),
        );

        $item = $cache->getItem($this->keyFor('app:some:command'));
        $item->set('not-a-timestamp');
        $cache->save($item);

        self::assertNull($recorder->lastRun('app:some:command'));
    }

    /**
     * Two commands that sanitise to the same readable key (here: a space and a colon both become a dash) must still
     * be told apart, which is what the hash suffix is for.
     */
    public function testCommandsThatSanitiseAlikeAreKeptApart(): void
    {
        $recorder = $this->recorder();

        $recorder->record(
            'a:b',
            new DateTimeImmutable('@10'),
        );
        $recorder->record(
            'a b',
            new DateTimeImmutable('@20'),
        );

        self::assertSame(
            20,
            $recorder->lastRun('a b')?->getTimestamp(),
        );
        self::assertSame(
            10,
            $recorder->lastRun('a:b')?->getTimestamp(),
        );
    }

    private function recorder(?DateTimeImmutable $now = null): ScheduledRunRecorder
    {
        return new ScheduledRunRecorder(
            new ArrayAdapter(),
            new MockClock($now ?? new DateTimeImmutable('@0')),
        );
    }

    private function keyFor(string $command): string
    {
        return 'scheduled_run.'
            . preg_replace(
                '/[^A-Za-z0-9_.]/',
                '-',
                $command,
            )
            . '.'
            . substr(
                hash(
                    'sha256',
                    $command,
                ),
                0,
                8,
            );
    }
}
