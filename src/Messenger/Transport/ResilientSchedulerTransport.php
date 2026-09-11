<?php

declare(strict_types=1);

namespace App\Messenger\Transport;

use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function func_get_args;

/**
 * The same as {@see ResilientAmqpTransport} for the schedule, which reads its checkpoint from Valkey and so has an
 * outage of its own to survive. `SchedulerTransport` implements `TransportInterface` and nothing else.
 */
final readonly class ResilientSchedulerTransport implements TransportInterface
{
    use SurvivesReceiveFailureTrait;

    public function __construct(
        private TransportInterface $decorated,
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
        // See ResilientAmqpTransport::get() for why the argument is declared but forwarded positionally.
        $arguments = func_get_args();

        return $this->survivingReceiveFailure(
            $this->logger,
            fn (): iterable => $this->decorated->get(...$arguments),
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
}
