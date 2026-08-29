<?php

declare(strict_types=1);

namespace App\Tests\Controller\Application;

use App\Controller\Application\LocaleRedirectController;
use App\Service\Application\LocalePreference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class LocaleRedirectControllerTest extends TestCase
{
    public function testTheRouteParametersAreCarriedOverAndTheRouteNameIsNot(): void
    {
        $response = $this->redirect(
            '/renew/abc-123',
            'join_renew',
            ['token' => 'abc-123'],
        );

        self::assertSame(
            '/en/renew/abc-123',
            $response->getTargetUrl(),
        );
    }

    /**
     * Losing this would leave the visitor on a page that cannot say what happened to their payment.
     */
    public function testTheQueryStringIsCarriedOver(): void
    {
        $response = $this->redirect(
            '/checkout/completed?stripe_session_id=cs_test_123',
            'join_checkout_completed',
        );

        self::assertSame(
            '/en/checkout/completed?stripe_session_id=cs_test_123',
            $response->getTargetUrl(),
        );
    }

    #[DataProvider('acceptLanguages')]
    public function testTheLanguageIsTheOneTheBrowserAsksFor(
        string $acceptLanguage,
        string $expected,
    ): void {
        $response = $this->redirect(
            '/checkout/completed',
            'join_checkout_completed',
            acceptLanguage: $acceptLanguage,
        );

        self::assertSame(
            '/' . $expected . '/checkout/completed',
            $response->getTargetUrl(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptLanguages(): array
    {
        return [
            'Dutch' => [
                'nl-NL,nl;q=0.9',
                'nl',
            ],
            'English' => [
                'en-GB,en;q=0.9',
                'en',
            ],
            'Dutch preferred over English' => [
                'nl;q=0.9,en;q=0.8',
                'nl',
            ],
            // Neither is on offer, so the default answers rather than nothing.
            'a language we do not speak' => [
                'de-DE,de;q=0.9',
                'en',
            ],
            'nothing asked for' => [
                '',
                'en',
            ],
        ];
    }

    /**
     * Where this lands depends on the request, so it must not be one a browser may remember.
     */
    public function testTheRedirectIsTemporary(): void
    {
        self::assertSame(
            302,
            $this->redirect(
                '/checkout/completed',
                'join_checkout_completed',
            )->getStatusCode(),
        );
    }

    /**
     * @param array<string, string> $routeParameters
     */
    private function redirect(
        string $uri,
        string $route,
        array $routeParameters = [],
        string $acceptLanguage = 'en',
    ): RedirectResponse {
        $request = Request::create($uri);
        $request->headers->set(
            'Accept-Language',
            $acceptLanguage,
        );
        $request->attributes->set(
            '_route_params',
            ['route' => $route] + $routeParameters,
        );

        $controller = new LocaleRedirectController(
            $this->urlGenerator(),
            new LocalePreference(
                [
                    'en',
                    'nl',
                ],
                'en',
            ),
        );

        return $controller(
            $request,
            $route,
        );
    }

    private function urlGenerator(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add(
            'join_renew',
            new Route(
                '/{_locale}/renew/{token}',
                requirements: ['_locale' => 'en|nl'],
            ),
        );
        $routes->add(
            'join_checkout_completed',
            new Route(
                '/{_locale}/checkout/completed',
                requirements: ['_locale' => 'en|nl'],
            ),
        );

        return new UrlGenerator(
            $routes,
            new RequestContext(),
        );
    }
}
