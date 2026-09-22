<?php

declare(strict_types=1);

namespace App\Tests\Service\Application;

use App\Service\Application\LocalePreference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class LocalePreferenceTest extends TestCase
{
    public function testTheSessionWinsOverTheBrowserLanguage(): void
    {
        self::assertSame(
            'nl',
            $this->preference()->resolve($this->request(
                'en-GB,en;q=0.9',
                'nl',
            )),
        );
    }

    public function testAnUnsupportedRememberedLanguageIsIgnored(): void
    {
        self::assertSame(
            'en',
            $this->preference()->resolve($this->request(
                'en-GB,en;q=0.9',
                'de',
            )),
        );
    }

    /**
     * Reading the session when the browser presents no session cookie would start one for every visitor of `/`.
     */
    public function testWithoutASessionTheBrowserLanguageDecidesAndNoSessionIsStarted(): void
    {
        $request = $this->request('nl-NL,nl;q=0.9');

        self::assertSame(
            'nl',
            $this->preference()->resolve($request),
        );
        self::assertFalse($request->getSession()->isStarted());
    }

    public function testThePageLanguageIsRemembered(): void
    {
        $request = $this->request('en');
        $request->attributes->set(
            '_locale',
            'nl',
        );

        $this->preference()->remember($request);

        self::assertSame(
            'nl',
            $request->getSession()->get('_locale'),
        );
    }

    public function testAPageWithoutALanguageLeavesTheSessionAlone(): void
    {
        $request = $this->request('en');

        $this->preference()->remember($request);

        self::assertFalse($request->getSession()->isStarted());
    }

    private function preference(): LocalePreference
    {
        return new LocalePreference(
            [
                'en',
                'nl',
            ],
            'en',
        );
    }

    private function request(
        string $acceptLanguage,
        ?string $remembered = null,
    ): Request {
        $request = Request::create('/');
        $request->headers->set(
            'Accept-Language',
            $acceptLanguage,
        );

        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        if (null !== $remembered) {
            $session->set(
                '_locale',
                $remembered,
            );
            $request->cookies->set(
                $session->getName(),
                'previous',
            );
        }

        return $request;
    }
}
