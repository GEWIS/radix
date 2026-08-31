<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\SudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SudoModeTest extends TestCase
{
    public function testAPasswordJustTypedUnlocksTheFirewallItWasTypedOn(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
        );
        $sudo->grant();

        self::assertTrue($sudo->isActive());
    }

    public function testAGrantOnOneFirewallIsNotAGrantOnTheOther(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $this->sudoMode(
            $session,
            $tokenStorage,
            'company',
        )->grant();

        self::assertFalse($this->sudoMode(
            $session,
            $tokenStorage,
            'main',
        )->isActive());
    }

    public function testAGrantDoesNotSurviveTheSessionBecomingSomebodyElses(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
        );
        $sudo->grant();

        $tokenStorage->setToken(new UsernamePasswordToken(
            new InMemoryUser(
                '8001',
                null,
            ),
            'main',
        ));

        self::assertFalse($sudo->isActive());
    }

    public function testAGrantRunsOut(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');
        $clock = new MockClock();

        $sudo = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
            $clock,
        );
        $sudo->grant();

        $clock->modify('+1801 seconds');

        self::assertFalse($sudo->isActive());
    }

    public function testRevokingClearsOnlyTheFirewallItIsAskedFor(): void
    {
        $session = $this->session();
        $tokenStorage = $this->tokenStorage('8025');

        $main = $this->sudoMode(
            $session,
            $tokenStorage,
            'main',
        );
        $company = $this->sudoMode(
            $session,
            $tokenStorage,
            'company',
        );

        $main->grant();
        $company->grant();
        $company->revoke();

        self::assertTrue($main->isActive());
        self::assertFalse($company->isActive());
    }

    private function sudoMode(
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        string $firewall,
        ?MockClock $clock = null,
    ): SudoMode {
        // A grant is only read back off a session the request already carried, so the cookie has to be there.
        $request = new Request(cookies: [$session->getName() => 'a-session-id']);
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $firewallMap = self::createStub(FirewallMap::class);
        $firewallMap->method('getFirewallConfig')->willReturn(new FirewallConfig(
            $firewall,
            'security.user_checker',
        ));

        return new SudoMode(
            $requestStack,
            $clock ?? new MockClock(),
            $firewallMap,
            $tokenStorage,
        );
    }

    private function session(): SessionInterface
    {
        return new Session(new MockArraySessionStorage());
    }

    private function tokenStorage(string $userIdentifier): TokenStorageInterface
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(
            new InMemoryUser(
                $userIdentifier,
                null,
            ),
            'main',
        ));

        return $tokenStorage;
    }
}
