<?php

declare(strict_types=1);

namespace App\Monolog\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Monolog\ResettableInterface;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

use function bin2hex;
use function random_bytes;

/**
 * Stamps every record of one request with the same identifier, so the lines a single visit produced can be read
 * together. Without it a torn-down session is a line in a file with no way back to the request that caused it, and no
 * way to line the file up against the {@see \App\Entity\User\SecurityLog} row written beside it.
 *
 * The identifier is ours rather than the proxy's: a header a visitor can set is a header a visitor can use to make
 * two unrelated requests look like one.
 *
 * Held on the processor and cleared between requests, because under FrankenPHP's worker mode the service outlives the
 * request; an identifier that stayed would file the next visitor's records under the previous one's.
 */
final class RequestIdProcessor implements ProcessorInterface, EventSubscriberInterface, ResettableInterface
{
    /** Where the current request carries its own identifier, for anything that wants to name it in a response. */
    public const string ATTRIBUTE = '_app_request_id';

    private ?string $requestId = null;

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['request_id'] = $this->current();

        return $record;
    }

    /**
     * The identifier of whatever is running now. Generated on demand so that a console command, a worker and a
     * sub-request that arrives before {@see self::onKernelRequest()} all have one.
     */
    public function current(): string
    {
        return $this->requestId ??= bin2hex(random_bytes(8));
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->requestId = bin2hex(random_bytes(8));
        $event->getRequest()->attributes->set(
            self::ATTRIBUTE,
            $this->requestId,
        );
    }

    #[Override]
    public function reset(): void
    {
        $this->requestId = null;
    }

    /**
     * Ahead of every listener that could log, the security guards included, so nothing a request produces is filed
     * under the identifier of the request before it.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => [
                'onKernelRequest',
                4096,
            ],
        ];
    }
}
