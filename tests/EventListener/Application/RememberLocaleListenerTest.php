<?php

declare(strict_types=1);

namespace App\Tests\EventListener\Application;

use App\EventListener\Application\RememberLocaleListener;
use App\Service\Application\LocalePreference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The locale a visitor chose is remembered for the next visit, once the router has named it.
 */
final class RememberLocaleListenerTest extends TestCase
{
    public function testTheChosenLocaleIsRemembered(): void
    {
        $request = $this->request('nl');

        $this->listen($request);

        self::assertSame(
            'nl',
            $request->getSession()->get('_locale'),
        );
    }

    public function testALocaleThatIsNotSupportedIsNotRemembered(): void
    {
        $request = $this->request('de');

        $this->listen($request);

        self::assertNull($request->getSession()->get('_locale'));
    }

    public function testASubRequestDoesNotRememberAnything(): void
    {
        $request = $this->request('nl');

        $this->listen(
            $request,
            HttpKernelInterface::SUB_REQUEST,
        );

        self::assertNull($request->getSession()->get('_locale'));
    }

    private function listen(
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        (new RememberLocaleListener(new LocalePreference(['en', 'nl'], 'en')))(
            new RequestEvent(
                self::createStub(HttpKernelInterface::class),
                $request,
                $type,
            ),
        );
    }

    private function request(string $locale): Request
    {
        $request = Request::create('/' . $locale);
        $request->attributes->set(
            '_locale',
            $locale,
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
