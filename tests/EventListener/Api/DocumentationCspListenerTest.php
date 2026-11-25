<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Api;

use App\EventListener\Api\DocumentationCspListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Swagger UI's own scripts are what `strict-dynamic` would let through, and the documentation page is the one place
 * that needs it removed rather than the whole site's policy loosened.
 */
final class DocumentationCspListenerTest extends TestCase
{
    public function testStrictDynamicIsRemovedFromTheDocumentationPolicy(): void
    {
        $response = new Response(
            '',
            Response::HTTP_OK,
            [
                'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'strict-dynamic' 'unsafe-inline'",
            ],
        );

        $this->listen(
            '/api-docs',
            $response,
        );

        self::assertSame(
            "default-src 'self'; script-src 'self' 'unsafe-inline'",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testTheReportOnlyPolicyIsTreatedTheSameWay(): void
    {
        $response = new Response(
            '',
            Response::HTTP_OK,
            [
                'Content-Security-Policy-Report-Only' => "script-src 'self' 'strict-dynamic'",
            ],
        );

        $this->listen(
            '/api-docs',
            $response,
        );

        self::assertSame(
            "script-src 'self'",
            $response->headers->get('Content-Security-Policy-Report-Only'),
        );
    }

    public function testAPolicyWithoutStrictDynamicIsLeftAlone(): void
    {
        $response = new Response(
            '',
            Response::HTTP_OK,
            [
                'Content-Security-Policy' => "default-src 'self'",
            ],
        );

        $this->listen(
            '/api-docs',
            $response,
        );

        self::assertSame(
            "default-src 'self'",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    public function testAResponseWithNoPolicyIsLeftAlone(): void
    {
        $response = new Response('');

        $this->listen(
            '/api-docs',
            $response,
        );

        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function testEveryOtherPageKeepsItsPolicy(): void
    {
        $response = new Response(
            '',
            Response::HTTP_OK,
            [
                'Content-Security-Policy' => "script-src 'self' 'strict-dynamic'",
            ],
        );

        $this->listen(
            '/en/members',
            $response,
        );

        self::assertSame(
            "script-src 'self' 'strict-dynamic'",
            $response->headers->get('Content-Security-Policy'),
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

        new DocumentationCspListener()($event);
    }
}
