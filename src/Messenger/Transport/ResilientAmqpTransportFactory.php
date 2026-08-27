<?php

declare(strict_types=1);

namespace App\Messenger\Transport;

use Monolog\Attribute\WithMonologChannel;
use Override;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Wraps every AMQP transport as it is built, rather than naming each of the nine in `services.yaml`. The test
 * environment routes them all to `in-memory://`, which this factory is not asked for, so nothing there is wrapped.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
#[AsDecorator(decorates: 'messenger.transport.amqp.factory')]
#[WithMonologChannel('messenger')]
final readonly class ResilientAmqpTransportFactory implements TransportFactoryInterface
{
    /**
     * @param TransportFactoryInterface<TransportInterface> $decorated
     */
    public function __construct(
        #[AutowireDecorated]
        private TransportFactoryInterface $decorated,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @param array<mixed, mixed> $options
     */
    #[Override]
    public function createTransport(
        #[SensitiveParameter]
        string $dsn,
        array $options,
        SerializerInterface $serializer,
    ): TransportInterface {
        $transport = $this->decorated->createTransport(
            $dsn,
            $options,
            $serializer,
        );

        // Left alone rather than wrapped if it is not the shape ResilientAmqpTransport delegates to, so a change to
        // what AmqpTransport implements costs the resilience rather than the boot.
        if (
            !$transport instanceof QueueReceiverInterface
            || !$transport instanceof SetupableTransportInterface
            || !$transport instanceof CloseableTransportInterface
            || !$transport instanceof MessageCountAwareInterface
        ) {
            return $transport;
        }

        return new ResilientAmqpTransport(
            $transport,
            $this->logger,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param array<mixed, mixed> $options
     */
    #[Override]
    public function supports(
        #[SensitiveParameter]
        string $dsn,
        array $options,
    ): bool {
        return $this->decorated->supports(
            $dsn,
            $options,
        );
    }
}
