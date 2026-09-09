<?php

declare(strict_types=1);

namespace App\EventListener\Messenger;

use App\Service\Application\ScheduledRunRecorder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Notes that a scheduled command completed, so {@see ScheduledTaskStatusProvider} can say which ones have stopped.
 *
 * Handled is enough to mean it succeeded: `RunCommandMessage` is constructed with `throwOnFailure`, so a non-zero
 * exit throws out of the handler and the envelope goes to `failed` instead of reaching this event.
 *
 * Every `RunCommandMessage` is recorded, not only the scheduled ones. A command dispatched by hand also ran, and
 * separating the two would mean reading the schedule on the worker for no benefit.
 */
#[AsEventListener(event: WorkerMessageHandledEvent::class)]
final readonly class RecordScheduledRunListener
{
    public function __construct(
        private ScheduledRunRecorder $recorder,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkerMessageHandledEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (!$message instanceof RunCommandMessage) {
            return;
        }

        $this->recorder->record(
            (string) $message,
            $this->clock->now(),
        );
    }
}
