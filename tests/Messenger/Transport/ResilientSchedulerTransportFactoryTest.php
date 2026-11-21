<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Transport;

use App\Messenger\Transport\ResilientSchedulerTransport;
use App\Messenger\Transport\ResilientSchedulerTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function iterator_to_array;

/**
 * The schedule factory always wraps what it builds: there is one implementation, and without the decorator a Valkey
 * hiccup stops the schedule worker.
 */
final class ResilientSchedulerTransportFactoryTest extends TestCase
{
    public function testTheBuiltScheduleIsWrapped(): void
    {
        $decoratedFactory = self::createStub(TransportFactoryInterface::class);
        $decoratedFactory->method('createTransport')->willReturn(
            self::createStub(TransportInterface::class),
        );

        $factory = new ResilientSchedulerTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        self::assertInstanceOf(
            ResilientSchedulerTransport::class,
            $factory->createTransport(
                'schedule://valkey',
                [],
                self::createStub(SerializerInterface::class),
            ),
        );
    }

    public function testTheDsnAndOptionsReachTheWrappedFactoryUnchanged(): void
    {
        $serializer = self::createStub(SerializerInterface::class);

        $decoratedFactory = $this->createMock(TransportFactoryInterface::class);
        $decoratedFactory->expects(self::once())
            ->method('createTransport')
            ->with(
                'schedule://valkey',
                ['checkpoint' => 'cron'],
                $serializer,
            )
            ->willReturn(self::createStub(TransportInterface::class));

        $factory = new ResilientSchedulerTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );
        $factory->createTransport(
            'schedule://valkey',
            ['checkpoint' => 'cron'],
            $serializer,
        );
    }

    public function testSupportsIsAnsweredByTheWrappedFactory(): void
    {
        $decoratedFactory = self::createStub(TransportFactoryInterface::class);
        $decoratedFactory->method('supports')->willReturn(true);

        $factory = new ResilientSchedulerTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        self::assertTrue($factory->supports(
            'schedule://valkey',
            [],
        ));
    }

    public function testTheWrappedScheduleStillReceives(): void
    {
        $envelope = new Envelope(new stdClass());
        $schedule = self::createStub(TransportInterface::class);
        $schedule->method('get')->willReturn([$envelope]);

        $decoratedFactory = self::createStub(TransportFactoryInterface::class);
        $decoratedFactory->method('createTransport')->willReturn($schedule);

        $factory = new ResilientSchedulerTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        $transport = $factory->createTransport(
            'schedule://valkey',
            [],
            self::createStub(SerializerInterface::class),
        );

        self::assertSame(
            [$envelope],
            iterator_to_array($transport->get()),
        );
    }
}
