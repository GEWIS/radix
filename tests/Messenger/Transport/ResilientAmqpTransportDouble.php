<?php

declare(strict_types=1);

namespace App\Tests\Messenger\Transport;

use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\CloseableTransportInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\SetupableTransportInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function func_get_args;

/**
 * The full AMQP transport interface, so the decorator can wrap it.
 *
 * @internal
 */
final class ResilientAmqpTransportDouble implements
    TransportInterface,
    QueueReceiverInterface,
    SetupableTransportInterface,
    CloseableTransportInterface,
    MessageCountAwareInterface
{
    /** @var list<Envelope> */
    public array $sent = [];

    /** @var list<Envelope> */
    public array $acked = [];

    /** @var list<Envelope> */
    public array $rejected = [];

    /** @var list<mixed> */
    public array $lastArguments = [];

    public bool $setupCalled = false;
    public bool $closed = false;

    public ?TransportException $transportException = null;
    public ?RuntimeException $runtimeException = null;

    /**
     * @param list<Envelope> $envelopes
     */
    public function __construct(public array $envelopes = [])
    {
    }

    public static function failingOn(TransportException|RuntimeException $exception): self
    {
        $double = new self();

        if ($exception instanceof TransportException) {
            $double->transportException = $exception;

            return $double;
        }

        $double->runtimeException = $exception;

        return $double;
    }

    /**
     * @return iterable<Envelope>
     */
    public function get(int $fetchSize = 1): iterable
    {
        $this->lastArguments = func_get_args();

        return $this->receive();
    }

    /**
     * @param array<string> $queueNames
     *
     * @return iterable<Envelope>
     */
    public function getFromQueues(
        array $queueNames,
        int $fetchSize = 1,
    ): iterable {
        $this->lastArguments = func_get_args();

        return $this->receive();
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

    public function setup(): void
    {
        $this->setupCalled = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function getMessageCount(): int
    {
        return 42;
    }

    /**
     * @return iterable<Envelope>
     */
    private function receive(): iterable
    {
        if (null !== $this->transportException) {
            throw $this->transportException;
        }

        if (null !== $this->runtimeException) {
            throw $this->runtimeException;
        }

        yield from $this->envelopes;
    }
}
