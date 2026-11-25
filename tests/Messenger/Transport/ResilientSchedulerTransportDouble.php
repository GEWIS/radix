<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Transport;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function func_get_args;

/**
 * @internal
 */
final class ResilientSchedulerTransportDouble implements TransportInterface
{
    /** @var list<Envelope> */
    public array $sent = [];

    /** @var list<Envelope> */
    public array $acked = [];

    /** @var list<Envelope> */
    public array $rejected = [];

    /** @var list<mixed> */
    public array $lastArguments = [];

    /**
     * @param list<Envelope>|TransportException|RuntimeException $envelopes
     */
    public function __construct(
        private readonly array|TransportException|RuntimeException $envelopes,
    ) {
    }

    /**
     * @return iterable<Envelope>
     */
    public function get(int $fetchSize = 1): iterable
    {
        $this->lastArguments = func_get_args();

        if (
            $this->envelopes instanceof TransportException
            || $this->envelopes instanceof RuntimeException
        ) {
            throw $this->envelopes;
        }

        yield from $this->envelopes;
    }

    public function ack(Envelope $envelope): void
    {
        $this->acked[] = $envelope;
    }

    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;
    }

    public function send(Envelope $envelope): Envelope
    {
        $this->sent[] = $envelope;

        return $envelope;
    }
}
