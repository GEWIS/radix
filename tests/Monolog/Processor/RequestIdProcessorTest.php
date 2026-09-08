<?php

declare(strict_types=1);

namespace App\Tests\Monolog\Processor;

use App\Monolog\Processor\RequestIdProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestIdProcessorTest extends TestCase
{
    public function testEveryRecordOfOneRequestCarriesTheSameIdentifier(): void
    {
        $processor = new RequestIdProcessor();

        self::assertSame(
            $this->stamp($processor),
            $this->stamp($processor),
        );
    }

    /**
     * The reason it is reset at all. Under FrankenPHP's worker mode the processor outlives the request, and an
     * identifier left behind would file the next visitor's records under the previous one's.
     */
    public function testANewRequestGetsAnIdentifierOfItsOwn(): void
    {
        $processor = new RequestIdProcessor();

        $first = $this->stamp($processor);
        $processor->onKernelRequest($this->event());

        self::assertNotSame(
            $first,
            $this->stamp($processor),
        );
    }

    public function testResettingForgetsTheIdentifier(): void
    {
        $processor = new RequestIdProcessor();

        $first = $this->stamp($processor);
        $processor->reset();

        self::assertNotSame(
            $first,
            $this->stamp($processor),
        );
    }

    /**
     * So that anything answering the request -- an error page, a support reply -- can name the same identifier the
     * log lines carry.
     */
    public function testTheRequestIsToldWhatItIsCalled(): void
    {
        $processor = new RequestIdProcessor();
        $event = $this->event();

        $processor->onKernelRequest($event);

        self::assertSame(
            $processor->current(),
            $event->getRequest()->attributes->get(RequestIdProcessor::ATTRIBUTE),
        );
    }

    /**
     * A sub-request is part of the request that caused it and is not a new one.
     */
    public function testASubRequestDoesNotStartOver(): void
    {
        $processor = new RequestIdProcessor();

        $processor->onKernelRequest($this->event());
        $before = $processor->current();

        $processor->onKernelRequest($this->event(HttpKernelInterface::SUB_REQUEST));

        self::assertSame(
            $before,
            $processor->current(),
        );
    }

    private function stamp(RequestIdProcessor $processor): string
    {
        $record = $processor(new LogRecord(
            new DateTimeImmutable(),
            'security',
            Level::Info,
            'sign_in_succeeded',
        ));

        $requestId = $record->extra['request_id'];
        self::assertIsString($requestId);

        return $requestId;
    }

    private function event(int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            $type,
        );
    }
}
