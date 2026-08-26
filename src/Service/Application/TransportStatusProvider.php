<?php

declare(strict_types=1);

namespace App\Service\Application;

use App\ViewModel\Application\FailedMessageList;
use App\ViewModel\Application\FailedMessageRow;
use App\ViewModel\Application\TransportStatus;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Throwable;

use function array_keys;
use function array_values;
use function count;
use function get_debug_type;
use function is_scalar;
use function spl_object_id;
use function strcmp;
use function usort;

/**
 * Reads what the messenger transports currently hold, for the administration's queue page. Read-only: it counts and
 * lists, and never acknowledges, retries or removes anything.
 *
 * This is the same information `messenger:stats` and `messenger:failed:show` print, which on this deployment means
 * opening a shell on a container. A queue that is quietly growing (an image backfill outrunning its workers, a
 * broker nobody noticed had gone) is worth seeing without one.
 */
final readonly class TransportStatusProvider
{
    /**
     * How many failed messages are read before paging over them. The transport decodes every envelope it hands out
     * and cannot be asked for an offset, so the whole page set is read on every view; the cap is what keeps an
     * administrator opening this page from deserialising an unbounded backlog.
     */
    private const int MAX_INSPECTED = 500;

    /**
     * @param ServiceLocator<object> $transportLocator
     */
    public function __construct(
        private ServiceLocator $transportLocator,
        private string $failureTransportName,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Every transport and what is waiting on it, the failure transport last.
     *
     * @return list<TransportStatus>
     */
    public function transports(): array
    {
        $statuses = [];
        foreach ($this->transportNames() as $name) {
            $transport = $this->transportLocator->get($name);

            $waiting = null;
            if ($transport instanceof MessageCountAwareInterface) {
                try {
                    $waiting = $transport->getMessageCount();
                } catch (Throwable $throwable) {
                    // A broker that is down must not take the page down with it: the row then reads "unknown",
                    // which is the honest answer and is itself the thing worth seeing.
                    $this->logger->warning(
                        'Could not count transport "{transport}": {message}',
                        [
                            'transport' => $name,
                            'message' => $throwable->getMessage(),
                        ],
                    );
                }
            }

            $statuses[] = new TransportStatus(
                $name,
                $waiting,
                $name === $this->failureTransportName,
            );
        }

        usort(
            $statuses,
            static function (TransportStatus $a, TransportStatus $b): int {
                if ($a->isFailureTransport !== $b->isFailureTransport) {
                    return $a->isFailureTransport
                        ? 1
                        : -1;
                }

                return strcmp(
                    $a->name,
                    $b->name,
                );
            },
        );

        return $statuses;
    }

    /**
     * Everything readable off the failure transport, newest first, capped at {@see MAX_INSPECTED}.
     *
     * The transport answers `all()` in no defined order and takes no offset, so the cap takes an arbitrary
     * {@see MAX_INSPECTED} rather than the most recent ones; what is read is then sorted, which is the order worth
     * reading a failure list in. Whatever pages over this slices it, so the sort has to happen before that or the
     * pages would reshuffle under the reader.
     */
    public function failed(): FailedMessageList
    {
        if (!$this->transportLocator->has($this->failureTransportName)) {
            return new FailedMessageList(
                [],
                0,
                false,
            );
        }

        $transport = $this->transportLocator->get($this->failureTransportName);

        $transportTotal = null;
        if ($transport instanceof MessageCountAwareInterface) {
            try {
                $transportTotal = $transport->getMessageCount();
            } catch (Throwable $throwable) {
                $this->logger->warning(
                    'Could not count the failure transport: {message}',
                    ['message' => $throwable->getMessage()],
                );
            }
        }

        $rows = [];
        if ($transport instanceof ListableReceiverInterface) {
            try {
                foreach ($transport->all(self::MAX_INSPECTED) as $envelope) {
                    $rows[] = $this->row($envelope);
                }
            } catch (Throwable $throwable) {
                // `all()` decodes as it walks, so one message whose class no longer exists ends the walk. Show what
                // was read rather than nothing, and leave the reason in the log.
                $this->logger->warning(
                    'Could not read the whole failure transport: {message}',
                    ['message' => $throwable->getMessage()],
                );
            }
        }

        usort(
            $rows,
            static function (FailedMessageRow $a, FailedMessageRow $b): int {
                // A row without a redelivery stamp has no time to sort on, so it goes last rather than to 1970.
                return ($b->failedAt?->getTimestamp() ?? 0) <=> ($a->failedAt?->getTimestamp() ?? 0);
            },
        );

        return new FailedMessageList(
            $rows,
            $transportTotal,
            count($rows) >= self::MAX_INSPECTED,
        );
    }

    private function row(Envelope $envelope): FailedMessageRow
    {
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();
        $error = $envelope->last(ErrorDetailsStamp::class);
        /** @var list<RedeliveryStamp> $redeliveries */
        $redeliveries = $envelope->all(RedeliveryStamp::class);
        $lastRedelivery = $redeliveries[count($redeliveries) - 1] ?? null;

        $failedAt = null;
        if (null !== $lastRedelivery) {
            $failedAt = DateTimeImmutable::createFromInterface($lastRedelivery->getRedeliveredAt());
        }

        return new FailedMessageRow(
            is_scalar($id) ? (string) $id : null,
            get_debug_type($envelope->getMessage()),
            $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
            $failedAt,
            $error?->getExceptionClass(),
            $error?->getExceptionMessage(),
            count($redeliveries),
        );
    }

    /**
     * The bare transport names. The locator holds every transport twice, under its service id and under its
     * configured name, both pointing at the same instance; the configured name is registered second, so keeping the
     * last name seen for each instance leaves the readable one (which is what `messenger:stats` shows too).
     *
     * @return list<string>
     */
    private function transportNames(): array
    {
        $names = [];
        foreach (array_keys($this->transportLocator->getProvidedServices()) as $name) {
            $names[spl_object_id($this->transportLocator->get($name))] = $name;
        }

        return array_values($names);
    }
}
