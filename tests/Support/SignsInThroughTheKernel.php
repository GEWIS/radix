<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\User\HandlerRegistry;
use App\Security\User\SudoMode;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

use function serialize;

/**
 * The cookies a signed-in request needs, for a test that dispatches through the kernel rather than calling a
 * controller directly.
 *
 * Two cookies are required per firewall. The token is stored on the session, and
 * {@see \App\EventListener\User\StaleSessionGuardListener} deauthenticates any authenticated request that arrives
 * without the remember-me cookie naming its session row, because a session without one was copied. A test that sets
 * only the session cookie is therefore signed out on its first request, which is indistinguishable from never having
 * signed in.
 *
 * The cookies and their database rows are created by the application's own handler, so a test uses the same values
 * that signing in produces.
 *
 * A member and a company representative sign in to different firewalls, each with its own provider, remember-me
 * cookie and sudo area, so a test that needs both requests both. One session is used for both: the tokens are stored
 * under a separate key per firewall and the remember-me cookies have distinct names.
 */
trait SignsInThroughTheKernel
{
    /**
     * @return array<string, string> cookie name => value, to set on every request of the test
     */
    private function signedInCookies(
        ?UserInterface $member = null,
        ?UserInterface $companyUser = null,
    ): array {
        $session = $this->newSession();
        $cookies = [$session->getName() => $session->getId()];

        if (null !== $member) {
            $cookies += $this->signIn(
                $session,
                $member,
                'main',
                '/en/user/settings/general',
            );
        }

        if (null !== $companyUser) {
            $cookies += $this->signIn(
                $session,
                $companyUser,
                'company',
                '/en/company/security',
            );
        }

        return $cookies;
    }

    /**
     * Signs one user in to one firewall on a session that may already contain another, and returns the remember-me
     * cookie to send with each request.
     *
     * @return array<string, string>
     */
    private function signIn(
        SessionInterface $session,
        UserInterface $user,
        string $firewall,
        string $insideFirewall,
    ): array {
        $container = self::getContainer();

        $token = new UsernamePasswordToken(
            $user,
            $firewall,
            $user->getRoles(),
        );

        $session->set(
            '_security_' . $firewall,
            serialize($token),
        );
        $session->save();

        // Both the session row and the sudo grant are keyed on this request: the row records the PHP session id and
        // the grant records the firewall, which is resolved from the path. A request without a path matches no
        // firewall, and neither is created.
        $request = Request::create($insideFirewall);
        $request->cookies->set(
            $session->getName(),
            $session->getId(),
        );
        $request->setSession($session);

        $requestStack = $container->get('request_stack');
        self::assertInstanceOf(
            RequestStack::class,
            $requestStack,
        );
        // Pushed only for the duration of this call. The handler and the grant both read the main request, which is
        // the bottom of the stack, so leaving one on the stack would make the second sign-in write its cookie to the
        // first request and key its grant to the first firewall.
        $requestStack->push($request);

        $tokenStorage = $container->get('security.token_storage');
        self::assertInstanceOf(
            TokenStorageInterface::class,
            $tokenStorage,
        );
        $tokenStorage->setToken($token);

        $registry = $container->get(HandlerRegistry::class);
        self::assertInstanceOf(
            HandlerRegistry::class,
            $registry,
        );

        $handler = $registry->get($firewall);
        self::assertNotNull(
            $handler,
            'The ' . $firewall . ' firewall is expected to have a remember-me handler.',
        );

        $handler->createRememberMeCookie($user);

        $rememberMe = $request->attributes->get(ResponseListener::COOKIE_ATTR_NAME);
        self::assertInstanceOf(
            Cookie::class,
            $rememberMe,
            'The handler is expected to have set a remember-me cookie.',
        );

        $container->get(SudoMode::class)->grant();

        $requestStack->pop();

        // Cleared now that the handler and the sudo grant have read it. A request dispatched through the kernel is
        // authenticated by the firewall on its path, and a route outside every firewall has no listener to overwrite
        // the token storage, so a token left here makes that route respond as authenticated.
        $tokenStorage->setToken(null);

        return [$rememberMe->getName() => (string) $rememberMe->getValue()];
    }

    private function newSession(): SessionInterface
    {
        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(
            SessionFactoryInterface::class,
            $factory,
        );

        $session = $factory->createSession();

        // Started here so that it has an id. A session that has not been started returns an empty id, and a cookie
        // with an empty value restores no session.
        $session->start();

        return $session;
    }
}
