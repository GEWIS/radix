<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Application;

use App\EventListener\Application\ClientHintsListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The high-entropy hints have to be asked for; they are what tells Windows 11 apart from Windows 10.
 */
final class ClientHintsListenerTest extends TestCase
{
    public function testTheHighEntropyHintsAreRequested(): void
    {
        $response = $this->listen();

        $accept = $response->headers->get('Accept-CH');
        self::assertIsString($accept);
        self::assertStringContainsString(
            'Sec-CH-UA-Platform-Version',
            $accept,
        );
        self::assertStringContainsString(
            'Sec-CH-UA-Model',
            $accept,
        );
    }

    public function testASubRequestDoesNotAskAgain(): void
    {
        $response = $this->listen(HttpKernelInterface::SUB_REQUEST);

        self::assertFalse($response->headers->has('Accept-CH'));
    }

    private function listen(int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $response = new Response();

        (new ClientHintsListener())(new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/en'),
            $type,
            $response,
        ));

        return $response;
    }
}
