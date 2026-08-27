<?php

declare(strict_types=1);

namespace App\Messenger\Transport;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * `Worker::run()` iterates the receiver outside any try/catch, so a broker that stops answering ends the process
 * with the same restart loop an unwritable failure transport used to cause. Yielding nothing instead leaves the
 * worker to sleep out its `--sleep` and ask again, in the process it already has.
 *
 * Only receiving is caught. An `ack()` that fails has to stay an error: swallowing it would report a message as
 * handled that the broker is going to hand out again.
 */
trait SurvivesReceiveFailureTrait
{
    /**
     * @param callable(): iterable<Envelope> $receive
     *
     * @return iterable<Envelope>
     */
    private function survivingReceiveFailure(
        LoggerInterface $logger,
        callable $receive,
    ): iterable {
        try {
            yield from $receive();
        } catch (TransportException $e) {
            $logger->error(
                'Could not receive from the transport, waiting rather than stopping the worker.',
                ['exception' => $e],
            );
        }
    }
}
