<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Transport;

use App\Messenger\Transport\ResilientAmqpTransport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;

use function iterator_to_array;

/**
 * A worker must survive a broker that stops answering. Only receive errors are swallowed; a failed ack stays an
 * error, or a message would count as handled and be delivered again.
 */
final class ResilientAmqpTransportTest extends TestCase
{
    public function testGetYieldsWhatTheWrappedTransportReceives(): void
    {
        $first = new Envelope(new stdClass());
        $second = new Envelope(new stdClass());
        $decorated = new ResilientAmqpTransportDouble([$first, $second]);

        $received = iterator_to_array(
            new ResilientAmqpTransport(
                $decorated,
                self::createStub(LoggerInterface::class),
            )->get(5),
        );

        self::assertSame(
            [
                $first,
                $second,
            ],
            $received,
        );
        // The fetch size is an extra argument the wrapped transport may not have declared yet, so it is forwarded
        // positionally rather than by name.
        self::assertSame(
            [5],
            $decorated->lastArguments,
        );
    }

    public function testGetFromQueuesForwardsTheQueuesAndFetchSize(): void
    {
        $envelope = new Envelope(new stdClass());
        $decorated = new ResilientAmqpTransportDouble([$envelope]);

        $received = iterator_to_array(
            new ResilientAmqpTransport(
                $decorated,
                self::createStub(LoggerInterface::class),
            )->getFromQueues(
                [
                    'bulk_queue',
                    'images_queue',
                ],
                7,
            ),
        );

        self::assertSame(
            [$envelope],
            $received,
        );
        self::assertSame(
            [
                [
                    'bulk_queue',
                    'images_queue',
                ],
                7,
            ],
            $decorated->lastArguments,
        );
    }

    public function testABrokerThatStopsAnsweringIsLoggedAndYieldsNothing(): void
    {
        $decorated = ResilientAmqpTransportDouble::failingOn(
            new TransportException('connection reset by peer'),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Could not receive from the transport, waiting rather than stopping the worker.',
                self::arrayHasKey('exception'),
            );

        $received = iterator_to_array(
            new ResilientAmqpTransport(
                $decorated,
                $logger,
            )->get(),
        );

        self::assertSame(
            [],
            $received,
        );
    }

    public function testAFailureToReceiveFromNamedQueuesIsAlsoSurvived(): void
    {
        $decorated = ResilientAmqpTransportDouble::failingOn(
            new TransportException('connection lost'),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $received = iterator_to_array(
            new ResilientAmqpTransport(
                $decorated,
                $logger,
            )->getFromQueues(['bulk_queue']),
        );

        self::assertSame(
            [],
            $received,
        );
    }

    public function testAnotherFailureIsNotSwallowedAsIfItWereABrokerOutage(): void
    {
        $decorated = ResilientAmqpTransportDouble::failingOn(new RuntimeException('a bug, not an outage'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('a bug, not an outage');

        iterator_to_array(
            new ResilientAmqpTransport(
                $decorated,
                self::createStub(LoggerInterface::class),
            )->get(),
        );
    }

    public function testEverythingElseIsDelegated(): void
    {
        $envelope = new Envelope(new stdClass());
        $decorated = new ResilientAmqpTransportDouble([]);
        $transport = new ResilientAmqpTransport(
            $decorated,
            self::createStub(LoggerInterface::class),
        );

        self::assertSame(
            $envelope,
            $transport->send($envelope),
        );
        $transport->ack($envelope);
        $transport->reject($envelope);
        $transport->setup();
        $transport->close();

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
        self::assertTrue($decorated->setupCalled);
        self::assertTrue($decorated->closed);
        self::assertSame(
            42,
            $transport->getMessageCount(),
        );
    }
}
