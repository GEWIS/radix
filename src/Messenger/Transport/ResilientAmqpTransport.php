<?php

declare(strict_types=1);

namespace App\Messenger\Transport;

use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface as Closeable;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface as CountAware;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface as QueueReceiver;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface as Setupable;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function func_get_args;

/**
 * Keeps a worker alive across a RabbitMQ outage; {@see SurvivesReceiveFailureTrait} for why.
 *
 * The interfaces are those of `AmqpTransport` and no more: a decorator claiming one the transport it wraps does not
 * have would fail wherever Messenger tests for it.
 */
final readonly class ResilientAmqpTransport implements
    QueueReceiver,
    TransportInterface,
    Setupable,
    Closeable,
    CountAware
{
    use SurvivesReceiveFailureTrait;

    public function __construct(
        private TransportInterface&QueueReceiver&Setupable&Closeable&CountAware $decorated,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * @return iterable<Envelope>
     */
    #[Override]
    public function get(int $fetchSize = 1): iterable
    {
        // `$fetchSize` is declared because the interface asks implementations to declare it, and forwarded through
        // `func_get_args()` because the transport being decorated has not declared it yet: there it is still the
        // extra argument Messenger passes, so passing it by name would be an argument the signature does not have.
        $arguments = func_get_args();

        return $this->survivingReceiveFailure(
            $this->logger,
            fn (): iterable => $this->decorated->get(...$arguments),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param string[] $queueNames
     *
     * @return iterable<Envelope>
     */
    #[Override]
    public function getFromQueues(
        array $queueNames,
        int $fetchSize = 1,
    ): iterable {
        $arguments = func_get_args();

        return $this->survivingReceiveFailure(
            $this->logger,
            fn (): iterable => $this->decorated->getFromQueues(...$arguments),
        );
    }

    #[Override]
    public function ack(Envelope $envelope): void
    {
        $this->decorated->ack($envelope);
    }

    #[Override]
    public function reject(Envelope $envelope): void
    {
        $this->decorated->reject($envelope);
    }

    #[Override]
    public function send(Envelope $envelope): Envelope
    {
        return $this->decorated->send($envelope);
    }

    #[Override]
    public function setup(): void
    {
        $this->decorated->setup();
    }

    #[Override]
    public function close(): void
    {
        $this->decorated->close();
    }

    #[Override]
    public function getMessageCount(): int
    {
        return $this->decorated->getMessageCount();
    }
}
