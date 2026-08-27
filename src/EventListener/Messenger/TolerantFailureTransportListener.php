<?php

declare(strict_types=1);

namespace App\EventListener\Messenger;

use Monolog\Attribute\WithMonologChannel;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageSkipEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

/**
 * Keeps a worker alive when the failure transport itself cannot be written to.
 *
 * `failed` is `doctrine://web`, so it is unreachable exactly when the website database is. Symfony dispatches the
 * failure event from `Worker::ack()` outside any try/catch, so the send throws out of the worker loop,
 * `messenger:consume` exits before the line that rejects the message, and RabbitMQ redelivers it to the container
 * Docker has just restarted. Every worker then loops on that.
 *
 * The envelope is held on `failed_fallback` instead, which is RabbitMQ and so is up in the case this exists for.
 * `app:messenger:recover-failures` moves it back. Losing it entirely takes both stores being unreachable at once.
 */
#[AsDecorator(decorates: 'messenger.failure.send_failed_message_to_failure_transport_listener')]
#[WithMonologChannel('messenger')]
final readonly class TolerantFailureTransportListener implements EventSubscriberInterface
{
    public function __construct(
        #[AutowireDecorated]
        private SendFailedMessageToFailureTransportListener $decorated,
        #[Autowire(service: 'messenger.transport.failed_fallback')]
        private SenderInterface $fallback,
        private LoggerInterface $logger,
    ) {
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        try {
            $this->decorated->onMessageFailed($event);
        } catch (Throwable $e) {
            $this->hold(
                $event,
                $e,
                new RedeliveryStamp(0),
            );
        }
    }

    public function onMessageSkip(WorkerMessageSkipEvent $event): void
    {
        try {
            $this->decorated->onMessageSkip($event);
        } catch (Throwable $e) {
            $this->hold(
                $event,
                $e,
            );
        }
    }

    /**
     * The stamps the listener being decorated would have added, so what is recovered is what it would have stored.
     */
    private function hold(
        AbstractWorkerMessageEvent $event,
        Throwable $throwable,
        StampInterface ...$stamps,
    ): void {
        $envelope = $event->getEnvelope()->with(
            new SentToFailureTransportStamp($event->getReceiverName()),
            new DelayStamp(0),
            ...$stamps,
        );

        $context = [
            'class' => $event->getEnvelope()->getMessage()::class,
            'receiver' => $event->getReceiverName(),
            'exception' => $throwable,
        ];

        try {
            $this->fallback->send($envelope);
        } catch (Throwable $e) {
            $this->logger->critical(
                'Could not send {class} to the failure transport or to the fallback, dropping it rather than '
                . 'stopping the worker.',
                $context + ['fallback_exception' => $e],
            );

            return;
        }

        $this->logger->error(
            'Could not send {class} to the failure transport, holding it on the fallback.',
            $context,
        );
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return SendFailedMessageToFailureTransportListener::getSubscribedEvents();
    }
}
