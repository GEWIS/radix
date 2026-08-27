<?php

declare(strict_types=1);

namespace App\Messenger\Transport;

use Monolog\Attribute\WithMonologChannel;
use Override;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * As {@see ResilientAmqpTransportFactory}, for the schedule.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
#[AsDecorator(decorates: 'scheduler.messenger_transport_factory')]
#[WithMonologChannel('messenger')]
final readonly class ResilientSchedulerTransportFactory implements TransportFactoryInterface
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
        return new ResilientSchedulerTransport(
            $this->decorated->createTransport(
                $dsn,
                $options,
                $serializer,
            ),
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
