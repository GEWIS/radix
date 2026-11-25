<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\KnownDeviceCookieListener;
use App\Service\User\KnownDeviceRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The device cookie left on the request by recognition is attached to the response, because Symfony's remember-me
 * attribute allows only one cookie.
 */
final class KnownDeviceCookieListenerTest extends TestCase
{
    public function testTheDeviceCookieIsAttached(): void
    {
        $cookie = new Cookie(
            'GWS_DEVICE',
            'a-token',
        );
        $request = Request::create('/en');
        $request->attributes->set(
            KnownDeviceRegistry::COOKIE_ATTRIBUTE,
            $cookie,
        );

        $response = $this->listen($request);

        self::assertSame(
            [$cookie],
            $response->headers->getCookies(),
        );
    }

    public function testARequestWithoutADeviceCookieAttachesNothing(): void
    {
        $response = $this->listen(Request::create('/en'));

        self::assertSame(
            [],
            $response->headers->getCookies(),
        );
    }

    public function testAValueThatIsNotACookieIsIgnored(): void
    {
        $request = Request::create('/en');
        $request->attributes->set(
            KnownDeviceRegistry::COOKIE_ATTRIBUTE,
            'not a cookie',
        );

        $response = $this->listen($request);

        self::assertSame(
            [],
            $response->headers->getCookies(),
        );
    }

    public function testASubRequestAttachesNothing(): void
    {
        $cookie = new Cookie(
            'GWS_DEVICE',
            'a-token',
        );
        $request = Request::create('/en');
        $request->attributes->set(
            KnownDeviceRegistry::COOKIE_ATTRIBUTE,
            $cookie,
        );

        $response = $this->listen(
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        self::assertSame(
            [],
            $response->headers->getCookies(),
        );
    }

    private function listen(
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): Response {
        $response = new Response();

        (new KnownDeviceCookieListener())(new ResponseEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            $type,
            $response,
        ));

        return $response;
    }
}
