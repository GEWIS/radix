<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Messenger;

use App\EventListener\Messenger\TolerantFailureTransportListener;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * The failure transport is the website database, so it is unreachable when the website is. A worker that dies there
 * would retry the same message, so the envelope stays in the fallback.
 */
final class TolerantFailureTransportListenerTest extends TestCase
{
    public function testAnEnvelopeTheFailureTransportRefusedIsHeldOnTheFallback(): void
    {
        $held = null;

        $listener = $this->listener(
            $this->decoratedThatFails(),
            $this->sender($held),
            $log = new TestHandler(),
        );

        $listener->onMessageFailed($this->failedEvent());

        self::assertInstanceOf(
            Envelope::class,
            $held,
        );
        self::assertNotNull($held->last(SentToFailureTransportStamp::class));
        self::assertSame(
            'async',
            $held->last(SentToFailureTransportStamp::class)->getOriginalReceiverName(),
        );
        self::assertNotNull($held->last(DelayStamp::class));
        self::assertSame(
            0,
            $held->last(DelayStamp::class)->getDelay(),
        );
        self::assertNotNull($held->last(RedeliveryStamp::class));

        self::assertTrue($log->hasRecordThatContains('holding it on the fallback', Level::Error));
    }

    public function testASkippedMessageIsHeldTheSameWay(): void
    {
        $held = null;

        $listener = $this->listener(
            $this->decoratedThatFails(),
            $this->sender($held),
            $log = new TestHandler(),
        );

        $listener->onMessageSkip(new WorkerMessageSkipEvent(
            new Envelope(new stdClass()),
            'async',
        ));

        self::assertInstanceOf(
            Envelope::class,
            $held,
        );
        self::assertNotNull($held->last(SentToFailureTransportStamp::class));
        self::assertTrue($log->hasRecordThatContains('holding it on the fallback', Level::Error));
    }

    /**
     * A message is lost only if both stores are unreachable at once, and the worker must survive that too.
     */
    public function testAnEnvelopeThatCannotBeHeldAnywhereIsDroppedRatherThanStoppingTheWorker(): void
    {
        $listener = $this->listener(
            $this->decoratedThatFails(),
            $this->senderThatFails(),
            $log = new TestHandler(),
        );

        $listener->onMessageFailed($this->failedEvent());

        self::assertTrue($log->hasRecordThatContains('dropping it rather than', Level::Critical));
    }

    public function testASuccessfulSendIsNotDuplicated(): void
    {
        $held = null;

        $decorated = self::createStub(SendFailedMessageToFailureTransportListener::class);

        $listener = $this->listener(
            $decorated,
            $this->sender($held),
            new TestHandler(),
        );

        $listener->onMessageFailed($this->failedEvent());

        self::assertNull($held);
    }

    public function testItListensToTheSameEventsAsWhatItDecorates(): void
    {
        self::assertSame(
            SendFailedMessageToFailureTransportListener::getSubscribedEvents(),
            TolerantFailureTransportListener::getSubscribedEvents(),
        );
    }

    private function listener(
        SendFailedMessageToFailureTransportListener $decorated,
        SenderInterface $fallback,
        TestHandler $log,
    ): TolerantFailureTransportListener {
        return new TolerantFailureTransportListener(
            $decorated,
            $fallback,
            new Logger(
                'messenger',
                [$log],
            ),
        );
    }

    private function decoratedThatFails(): SendFailedMessageToFailureTransportListener
    {
        $decorated = self::createStub(SendFailedMessageToFailureTransportListener::class);
        $decorated->method('onMessageFailed')->willThrowException(new RuntimeException('database is down'));
        $decorated->method('onMessageSkip')->willThrowException(new RuntimeException('database is down'));

        return $decorated;
    }

    private function sender(?Envelope &$held): SenderInterface
    {
        $sender = self::createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(
            static function (Envelope $envelope) use (&$held): Envelope {
                $held = $envelope;

                return $envelope;
            },
        );

        return $sender;
    }

    private function senderThatFails(): SenderInterface
    {
        $sender = self::createStub(SenderInterface::class);
        $sender->method('send')->willThrowException(new RuntimeException('rabbit is down too'));

        return $sender;
    }

    private function failedEvent(): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(
            new Envelope(new stdClass()),
            'async',
            new RuntimeException('the handler failed'),
        );
    }
}
