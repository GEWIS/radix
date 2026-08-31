<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\SudoGrantOnLoginListener;
use App\Security\User\SudoMode;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class SudoGrantOnLoginListenerTest extends TestCase
{
    public function testTypingAPasswordGrantsTheSudoMode(): void
    {
        $sudoMode = $this->sudoMode();

        $this->listener($sudoMode)($this->event($this->passwordPassport()));

        self::assertTrue($sudoMode->isActive());
    }

    /**
     * A session restored from a cookie has proven nothing, even though Symfony reports the login as interactive.
     */
    public function testARememberMeCookieGrantsNothing(): void
    {
        $sudoMode = $this->sudoMode();

        $this->listener($sudoMode)($this->event(new SelfValidatingPassport(
            new UserBadge('tom'),
            [new RememberMeBadge()],
        )));

        self::assertFalse($sudoMode->isActive());
    }

    /**
     * The password is in but the second factor is not, so this is not a login yet.
     */
    public function testAPendingSecondFactorGrantsNothing(): void
    {
        $sudoMode = $this->sudoMode();

        $this->listener($sudoMode)(
            $this->event(
                $this->passwordPassport(),
                token: self::createStub(TwoFactorTokenInterface::class),
            ),
        );

        self::assertFalse($sudoMode->isActive());
    }

    /**
     * A stateless firewall has no session to write a grant to.
     */
    public function testTheApiFirewallGrantsNothing(): void
    {
        $sudoMode = $this->sudoMode();

        $this->listener($sudoMode)(
            $this->event(
                $this->passwordPassport(),
                firewallName: 'api',
            ),
        );

        self::assertFalse($sudoMode->isActive());
    }

    public function testTheCompanyPortalGrantsLikeTheMainFirewall(): void
    {
        $sudoMode = $this->sudoMode('company');

        $this->listener($sudoMode)(
            $this->event(
                $this->passwordPassport(),
                firewallName: 'company',
            ),
        );

        self::assertTrue($sudoMode->isActive());
    }

    private function passwordPassport(): Passport
    {
        return new Passport(
            new UserBadge('tom'),
            new PasswordCredentials('correct horse battery staple'),
        );
    }

    private function sudoMode(string $firewall = 'main'): SudoMode
    {
        $session = new Session(new MockArraySessionStorage());
        // A grant is only read back off a session the request already carried, so the cookie has to be there.
        $request = new Request(cookies: [$session->getName() => 'a-session-id']);
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        // A grant is held against the firewall the request is on and the account it is signed in as, so both have to
        // be answerable here.
        $firewallMap = self::createStub(FirewallMap::class);
        $firewallMap->method('getFirewallConfig')->willReturn(new FirewallConfig(
            $firewall,
            'security.user_checker',
        ));

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(
            new InMemoryUser(
                'tom',
                null,
            ),
            $firewall,
        ));

        return new SudoMode(
            $requestStack,
            new MockClock(),
            $firewallMap,
            $tokenStorage,
        );
    }

    private function listener(SudoMode $sudoMode): SudoGrantOnLoginListener
    {
        return new SudoGrantOnLoginListener($sudoMode);
    }

    private function event(
        Passport $passport,
        ?TokenInterface $token = null,
        string $firewallName = 'main',
    ): LoginSuccessEvent {
        return new LoginSuccessEvent(
            self::createStub(AuthenticatorInterface::class),
            $passport,
            $token ?? self::createStub(TokenInterface::class),
            new Request(),
            null,
            $firewallName,
        );
    }
}
