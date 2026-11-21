<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Transport;

use App\Messenger\Transport\ResilientSchedulerTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;

use function iterator_to_array;

/**
 * The schedule keeps its checkpoint in Valkey, so a Valkey outage affects it. The schedule transport implements only
 * the plain transport interface, which is all the decorator implements.
 */
final class ResilientSchedulerTransportTest extends TestCase
{
    public function testGetYieldsWhatTheWrappedScheduleReceives(): void
    {
        $first = new Envelope(new stdClass());
        $second = new Envelope(new stdClass());
        $decorated = new ResilientSchedulerTransportDouble([$first, $second]);

        $received = iterator_to_array(
            new ResilientSchedulerTransport(
                $decorated,
                self::createStub(LoggerInterface::class),
            )->get(3),
        );

        self::assertSame(
            [
                $first,
                $second,
            ],
            $received,
        );
        self::assertSame(
            [3],
            $decorated->lastArguments,
        );
    }

    public function testACheckpointThatCannotBeReadIsLoggedAndYieldsNothing(): void
    {
        $decorated = new ResilientSchedulerTransportDouble(
            new TransportException('valkey is unreachable'),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Could not receive from the transport, waiting rather than stopping the worker.',
                self::arrayHasKey('exception'),
            );

        $received = iterator_to_array(
            new ResilientSchedulerTransport(
                $decorated,
                $logger,
            )->get(),
        );

        self::assertSame(
            [],
            $received,
        );
    }

    public function testAnotherFailureIsStillAnError(): void
    {
        $decorated = new ResilientSchedulerTransportDouble(new RuntimeException('a bug'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('a bug');

        iterator_to_array(
            new ResilientSchedulerTransport(
                $decorated,
                self::createStub(LoggerInterface::class),
            )->get(),
        );
    }

    public function testAckRejectAndSendAreDelegated(): void
    {
        $envelope = new Envelope(new stdClass());
        $decorated = new ResilientSchedulerTransportDouble([]);
        $transport = new ResilientSchedulerTransport(
            $decorated,
            self::createStub(LoggerInterface::class),
        );

        self::assertSame(
            $envelope,
            $transport->send($envelope),
        );
        $transport->ack($envelope);
        $transport->reject($envelope);

        self::assertSame(
            $envelope,
            $decorated->sent[0],
        );
        self::assertSame(
            [$envelope],
            $decorated->acked,
        );
        self::assertSame(
            [$envelope],
            $decorated->rejected,
        );
    }
}
