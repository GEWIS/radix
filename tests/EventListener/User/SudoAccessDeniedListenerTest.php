<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\SudoAccessDeniedListener;
use App\Security\User\SudoVoter;
use App\Tests\Support\BuildsSudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function http_build_query;
use function json_decode;
use function strval;

/**
 * The three shapes a refused request receives, and the page each one returns to.
 *
 * A navigation is redirected. A request the browser made for itself receives a 401 it can test, because `fetch`
 * follows a redirect and would report the confirmation page as a result. A live component is redirected again, but
 * for the opposite reason: it renders the body of anything it does not recognise into an overlay, and a redirect is
 * what it recognises.
 */
final class SudoAccessDeniedListenerTest extends TestCase
{
    use BuildsSudoMode;

    public function testANavigationIsRedirectedBackToTheAddressItWasRefusedAt(): void
    {
        $request = Request::create('/en/admin/pages/1/edit');
        $response = $this->refuse($request);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'next=%2Fen%2Fadmin%2Fpages%2F1%2Fedit',
            $response->getTargetUrl(),
        );
    }

    /**
     * A component address renders a component and not a page, so returning to it would leave the user looking at one
     * on its own. `X-Live-Url` is the page the component is on.
     */
    public function testALiveComponentIsRedirectedBackToThePageItIsOn(): void
    {
        $request = Request::create(
            '/en/_components/Activity:Admin:SignupOverview/sendTest',
            'POST',
        );
        $request->attributes->set(
            '_live_component',
            'Activity:Admin:SignupOverview',
        );
        $request->headers->set(
            'X-Live-Url',
            '/en/admin/activities/45/signups',
        );

        $response = $this->refuse($request);

        // A redirect rather than the 401 a background request would receive, because LiveComponentSubscriber turns
        // one into the 204 the component acts on and renders anything else into an overlay.
        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'next=%2Fen%2Fadmin%2Factivities%2F45%2Fsignups',
            $response->getTargetUrl(),
        );
    }

    public function testARequestTheBrowserMadeForItselfReceivesAStatusItCanTest(): void
    {
        $request = Request::create(
            '/en/admin/meetings/AV/42/documents/upload',
            'POST',
        );
        $request->headers->set(
            'X-Requested-With',
            'XMLHttpRequest',
        );
        $request->headers->set(
            'Referer',
            'http://localhost/en/admin/meetings/AV/42',
        );

        $response = $this->refuse($request);

        self::assertInstanceOf(
            JsonResponse::class,
            $response,
        );
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );

        $payload = json_decode(
            strval($response->getContent()),
            true,
        );
        self::assertIsArray($payload);
        self::assertSame(
            'sudo_required',
            $payload['error'],
        );
        self::assertStringContainsString(
            'next=%2Fen%2Fadmin%2Fmeetings%2FAV%2F42',
            strval($payload['confirmUrl']),
        );
    }

    private function refuse(Request $request): ?Response
    {
        $refused = new AccessDeniedException('This part of the site is behind sudo.');
        $refused->setAttributes(SudoVoter::ATTRIBUTE);

        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static function (string $route, array $parameters = []): string {
                return '/en/user/sudo?' . http_build_query($parameters);
            },
        );

        $firewallMap = self::createStub(FirewallMap::class);
        $firewallMap->method('getFirewallConfig')->willReturn(new FirewallConfig(
            'main',
            'security.user_checker',
        ));

        $listener = new SudoAccessDeniedListener(
            $urlGenerator,
            $firewallMap,
            $this->sudoStash(
                $this->session(),
                $this->tokenStorage('8025'),
            ),
        );

        $event = new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $refused,
        );
        $listener($event);

        return $event->getResponse();
    }
}
