<?php

declare(strict_types=1);

namespace App\Tests\Monolog\Processor;

use App\Monolog\Processor\RequestIdProcessor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * The identifier has to be assigned before any listener that could log, or a request's first lines are filed under the
 * identifier of the request before it.
 */
final class RequestIdProcessorSubscriptionTest extends TestCase
{
    public function testItListensToTheRequestBeforeAnyoneWhoCouldLog(): void
    {
        $events = RequestIdProcessor::getSubscribedEvents();

        self::assertArrayHasKey(
            RequestEvent::class,
            $events,
        );
        self::assertSame(
            'onKernelRequest',
            $events[RequestEvent::class][0],
        );
        self::assertGreaterThan(
            0,
            $events[RequestEvent::class][1],
        );
    }
}
