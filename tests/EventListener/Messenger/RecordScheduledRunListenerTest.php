<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Messenger;

use App\EventListener\Messenger\RecordScheduledRunListener;
use App\Service\Application\ScheduledRunRecorder;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * A schedule that stopped running is only visible if completed runs are recorded.
 */
final class RecordScheduledRunListenerTest extends TestCase
{
    public function testAHandledCommandIsRecordedAsHavingRun(): void
    {
        $clock = new MockClock('2026-08-31 12:00:00');
        $recorder = new ScheduledRunRecorder(
            new ArrayAdapter(),
            $clock,
        );

        $this->listener($recorder, $clock)($this->handled(new RunCommandMessage('app:activity:sync-agenda')));

        self::assertEquals(
            $clock->now(),
            $recorder->lastRun('app:activity:sync-agenda'),
        );
    }

    /**
     * A run dispatched by hand also ran; separating the two would mean reading the schedule on the worker.
     */
    public function testACommandDispatchedByHandIsRecordedToo(): void
    {
        $clock = new MockClock('2026-08-31 12:00:00');
        $recorder = new ScheduledRunRecorder(
            new ArrayAdapter(),
            $clock,
        );

        $this->listener($recorder, $clock)($this->handled(new RunCommandMessage('app:decision:generate')));

        self::assertNotNull($recorder->lastRun('app:decision:generate'));
    }

    public function testAnotherMessageIsNotACommandThatRan(): void
    {
        $clock = new MockClock('2026-08-31 12:00:00');
        $recorder = new ScheduledRunRecorder(
            new ArrayAdapter(),
            $clock,
        );

        $this->listener($recorder, $clock)($this->handled(new stdClass()));

        self::assertNull($recorder->lastRun('stdClass'));
    }

    private function listener(
        ScheduledRunRecorder $recorder,
        MockClock $clock,
    ): RecordScheduledRunListener {
        return new RecordScheduledRunListener(
            $recorder,
            $clock,
        );
    }

    private function handled(object $message): WorkerMessageHandledEvent
    {
        return new WorkerMessageHandledEvent(
            new Envelope($message),
            'cron',
        );
    }
}
