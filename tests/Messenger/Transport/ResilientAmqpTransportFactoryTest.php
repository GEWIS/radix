<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Transport;

use App\Messenger\Transport\ResilientAmqpTransport;
use App\Messenger\Transport\ResilientAmqpTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function assert;
use function iterator_to_array;

/**
 * Every AMQP transport is wrapped when built, without naming each of the nine in configuration. An incompatible
 * transport is left unwrapped, so an interface change costs the resilience and not the boot.
 */
final class ResilientAmqpTransportFactoryTest extends TestCase
{
    public function testAnAmqpShapedTransportIsWrapped(): void
    {
        $decoratedFactory = self::createStub(TransportFactoryInterface::class);
        $decoratedFactory->method('createTransport')->willReturn(
            new ResilientAmqpTransportDouble(),
        );

        $factory = new ResilientAmqpTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        $transport = $factory->createTransport(
            'amqp://guest:guest@rabbitmq:5672/%2f/messages',
            [],
            self::createStub(SerializerInterface::class),
        );

        self::assertInstanceOf(
            ResilientAmqpTransport::class,
            $transport,
        );
    }

    public function testATransportWithAFewerInterfacesIsReturnedAsItIs(): void
    {
        $transport = self::createStub(TransportInterface::class);
        $decoratedFactory = self::createStub(TransportFactoryInterface::class);
        $decoratedFactory->method('createTransport')->willReturn($transport);

        $factory = new ResilientAmqpTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        self::assertSame(
            $transport,
            $factory->createTransport(
                'amqp://guest:guest@rabbitmq:5672/%2f/messages',
                [],
                self::createStub(SerializerInterface::class),
            ),
        );
    }

    public function testTheDsnAndOptionsReachTheWrappedFactoryUnchanged(): void
    {
        $serializer = self::createStub(SerializerInterface::class);
        $options = [
            'exchange' => [
                'name' => 'bulk',
                'type' => 'direct',
            ],
        ];

        $decoratedFactory = $this->createMock(TransportFactoryInterface::class);
        $decoratedFactory->expects(self::once())
            ->method('createTransport')
            ->with(
                'amqp://host',
                $options,
                $serializer,
            )
            ->willReturn(new ResilientAmqpTransportDouble());

        $factory = new ResilientAmqpTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );
        $factory->createTransport(
            'amqp://host',
            $options,
            $serializer,
        );
    }

    public function testSupportsIsAnsweredByTheWrappedFactory(): void
    {
        $decoratedFactory = $this->createMock(TransportFactoryInterface::class);
        $decoratedFactory->expects(self::once())
            ->method('supports')
            ->with(
                'amqp://host',
                ['x' => 'y'],
            )
            ->willReturn(true);

        $factory = new ResilientAmqpTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        self::assertTrue($factory->supports(
            'amqp://host',
            ['x' => 'y'],
        ));

        $decoratedFactory = self::createStub(TransportFactoryInterface::class);
        $decoratedFactory->method('supports')->willReturn(false);
        $factory = new ResilientAmqpTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        self::assertFalse($factory->supports(
            'in-memory://',
            [],
        ));
    }

    public function testTheWrappedTransportStillReceivesWhenASingletonSeesNoFailure(): void
    {
        $envelope = new Envelope(new stdClass());
        $double = new ResilientAmqpTransportDouble();
        $decoratedFactory = self::createStub(TransportFactoryInterface::class);
        $decoratedFactory->method('createTransport')->willReturn($double);

        $factory = new ResilientAmqpTransportFactory(
            $decoratedFactory,
            self::createStub(LoggerInterface::class),
        );

        $transport = $factory->createTransport(
            'amqp://host',
            [],
            self::createStub(SerializerInterface::class),
        );
        assert($transport instanceof ResilientAmqpTransport);
        $double->envelopes = [$envelope];

        self::assertSame(
            [$envelope],
            iterator_to_array($transport->get()),
        );
    }
}
