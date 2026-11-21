<?php

declare(strict_types=1);

namespace App\Tests\Command\Application;

use App\Command\Application\RecoverFailedMessagesCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * The fallback transport exists for a failed website database, and this command is the only thing that drains it.
 * A message moves without the fallback's own message id, and a database that is still down keeps the rest in the
 * fallback.
 */
#[CoversClass(RecoverFailedMessagesCommand::class)]
final class RecoverFailedMessagesCommandTest extends TestCase
{
    public function testMovesEveryHeldFailureAndAcknowledgesIt(): void
    {
        $first = new Envelope(
            new stdClass(),
            [new TransportMessageIdStamp('fallback-1')],
        );
        $second = new Envelope(
            new stdClass(),
            [new TransportMessageIdStamp('fallback-2')],
        );

        $fallback = $this->fallback([[$first, $second], []]);
        $fallback->expects(self::exactly(2))->method('ack');

        $sent = [];
        $failed = self::createStub(SenderInterface::class);
        $failed->method('send')->willReturnCallback(
            static function (Envelope $envelope) use (&$sent): Envelope {
                $sent[] = $envelope;

                return $envelope;
            },
        );

        $tester = new CommandTester(new RecoverFailedMessagesCommand($fallback, $failed));
        $tester->execute([]);

        self::assertCount(
            2,
            $sent,
        );
        foreach ($sent as $envelope) {
            self::assertSame(
                [],
                $envelope->all(TransportMessageIdStamp::class),
                'The fallback\'s id would be passed into a store that assigns its own.',
            );
        }

        self::assertStringContainsString(
            'Recovered 2 failures.',
            $tester->getDisplay(),
        );
    }

    public function testAnEmptyFallbackRecoversNothing(): void
    {
        $fallback = $this->fallback([[]]);
        $fallback->expects(self::never())->method('ack');

        $failed = $this->createMock(SenderInterface::class);
        $failed->expects(self::never())->method('send');

        $tester = new CommandTester(new RecoverFailedMessagesCommand($fallback, $failed));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Recovered 0 failures.',
            $tester->getDisplay(),
        );
    }

    /**
     * A failure would be recorded on the fallback it drains, so the command reports success and leaves the rest for a
     * later run.
     */
    public function testAFailureTransportThatIsStillDownLeavesTheRestHeld(): void
    {
        $envelope = new Envelope(new stdClass());

        $fallback = $this->fallback([[$envelope, $envelope]]);
        $fallback->expects(self::never())->method('ack');

        $failed = self::createStub(SenderInterface::class);
        $failed->method('send')->willThrowException(new RuntimeException('database is down'));

        $tester = new CommandTester(new RecoverFailedMessagesCommand($fallback, $failed));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'The failure transport is still unreachable after 0',
            $tester->getDisplay(),
        );
    }

    /**
     * A run is bounded, so a backlog takes several runs and the output reports what is left.
     */
    public function testOneRunStopsAtItsLimit(): void
    {
        $envelope = new Envelope(new stdClass());

        $fallback = self::createStub(ReceiverInterface::class);
        $fallback->method('get')->willReturn([$envelope]);
        $fallback->method('ack');

        $failed = self::createStub(SenderInterface::class);
        $failed->method('send')->willReturnArgument(0);

        $tester = new CommandTester(new RecoverFailedMessagesCommand($fallback, $failed));
        $tester->execute([]);

        self::assertStringContainsString(
            'Recovered 1000 failures.',
            $tester->getDisplay(),
        );
        self::assertStringContainsString(
            'Stopped at this run\'s limit;',
            $tester->getDisplay(),
        );
    }

    /**
     * @param list<list<Envelope>> $batches what successive reads of the fallback return
     */
    private function fallback(array $batches): ReceiverInterface&MockObject
    {
        $fallback = $this->createMock(ReceiverInterface::class);
        $fallback->method('get')->willReturnOnConsecutiveCalls(...[...$batches, []]);

        return $fallback;
    }
}
