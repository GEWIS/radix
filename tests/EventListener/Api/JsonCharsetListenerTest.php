<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Api;

use App\EventListener\Api\JsonCharsetListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Consumers have matched `application/json; charset=utf-8` since the API existed, so the header keeps that value and
 * applies only to the API's JSON.
 */
final class JsonCharsetListenerTest extends TestCase
{
    public function testTheApisJsonKeepsItsCharset(): void
    {
        $response = new Response(
            '{}',
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );

        $this->listen(
            '/api/members',
            $response,
        );

        self::assertSame(
            'application/json; charset=utf-8',
            $response->headers->get('Content-Type'),
        );
    }

    public function testAnotherContentTypeIsLeftAlone(): void
    {
        $response = new Response(
            '{}',
            Response::HTTP_OK,
            ['Content-Type' => 'application/ld+json'],
        );

        $this->listen(
            '/api/members',
            $response,
        );

        self::assertSame(
            'application/ld+json',
            $response->headers->get('Content-Type'),
        );
    }

    public function testTheWebsiteIsLeftAlone(): void
    {
        $response = new Response(
            '{}',
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );

        $this->listen(
            '/en/members',
            $response,
        );

        self::assertSame(
            'application/json',
            $response->headers->get('Content-Type'),
        );
    }

    private function listen(
        string $path,
        Response $response,
    ): void {
        $event = new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        new JsonCharsetListener()($event);
    }
}
