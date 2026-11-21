<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Entity\User\User;
use App\Security\User\Firewall;
use App\Security\User\SudoSession;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * What a grant and everything stored beside it is keyed by: the firewall the request matches and the PHP session it was
 * made on.
 */
final class SudoSessionTest extends TestCase
{
    public function testTheKeyIsTheFirewallAndTheSession(): void
    {
        $session = $this->session('a-session-id');

        self::assertSame(
            'main_a-session-id',
            $this->sudoSession($this->requestStack($session))->current(),
        );
    }

    public function testWithoutARequestThereIsNoKey(): void
    {
        self::assertNull($this->sudoSession(new RequestStack())->current());
    }

    public function testARequestWithoutASessionHasNoKey(): void
    {
        self::assertNull(
            $this->sudoSession($this->requestStack(null))->current(),
        );
    }

    public function testARequestWithoutAFirewallHasNoKey(): void
    {
        self::assertNull(
            $this->sudoSession(
                $this->requestStack($this->session()),
                null,
            )->current(),
        );
    }

    /**
     * The API firewall is stateless, so there is no session to key a grant on.
     */
    public function testAStatelessFirewallHasNoKey(): void
    {
        self::assertNull(
            $this->sudoSession(
                $this->requestStack($this->session()),
                'api',
            )->current(),
        );
    }

    public function testAnUnstartedSessionIsStartedToGetItsId(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setId('an-unstarted-session');

        self::assertSame(
            'main_an-unstarted-session',
            $this->sudoSession($this->requestStack($session))->current(),
        );
    }

    public function testTheIdentifierIsTheSignedInAccount(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('8025', null), 'main'));

        self::assertSame(
            '8025',
            $this->sudoSession(
                new RequestStack(),
                tokenStorage: $tokenStorage,
            )->userIdentifier(),
        );
    }

    /**
     * A grant belongs to the administrator behind the switch, who is the only one that entered a password.
     */
    public function testWhileImpersonatingTheIdentifierIsTheAdministrator(): void
    {
        $administrator = new InMemoryUser(
            '8000',
            null,
        );
        $member = new InMemoryUser(
            '8030',
            null,
        );

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new SwitchUserToken(
            $member,
            'main',
            [],
            new UsernamePasswordToken(
                $administrator,
                'main',
            ),
        ));

        self::assertSame(
            '8000',
            $this->sudoSession(
                new RequestStack(),
                tokenStorage: $tokenStorage,
            )->userIdentifier(),
        );
    }

    public function testWithoutATokenThereIsNoIdentifier(): void
    {
        self::assertNull($this->sudoSession(new RequestStack())->userIdentifier());
    }

    public function testAnEmptyIdentifierIsNoIdentifier(): void
    {
        $user = self::createStub(User::class);
        $user->method('getUserIdentifier')->willReturn('');

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));

        self::assertNull($this->sudoSession(new RequestStack(), tokenStorage: $tokenStorage)->userIdentifier());
    }

    public function testTheKeyOfAGivenFirewallAndSessionIsStatedOnce(): void
    {
        self::assertSame(
            'company_a-session-id',
            $this->sudoSession(new RequestStack())->of(
                Firewall::Company,
                'a-session-id',
            ),
        );
    }

    private function sudoSession(
        RequestStack $requestStack,
        ?string $firewall = 'main',
        ?TokenStorage $tokenStorage = null,
    ): SudoSession {
        $firewallMap = self::createStub(FirewallMap::class);
        $firewallMap->method('getFirewallConfig')->willReturn(
            null === $firewall ? null : new FirewallConfig(
                $firewall,
                'user_checker',
            ),
        );

        return new SudoSession(
            $requestStack,
            $firewallMap,
            $tokenStorage ?? new TokenStorage(),
        );
    }

    private function requestStack(?Session $session): RequestStack
    {
        $request = Request::create('https://gewis.nl/en/admin');
        if (null !== $session) {
            $request->setSession($session);
        }

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return $requestStack;
    }

    private function session(string $id = 'a-session-id'): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setId($id);

        return $session;
    }
}
