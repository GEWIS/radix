<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\User\SudoMode;
use Redis;
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

/**
 * A {@see SudoMode} standing on a request of its own, for the tests of the class itself and of the listeners that
 * read and write grants.
 */
trait BuildsSudoMode
{
    use StandsInForValkey;

    private function sudoMode(
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        string $firewall = 'main',
        ?MockClock $clock = null,
        ?Redis $valkey = null,
    ): SudoMode {
        // The key of a grant is built from the session ID, so the session needs one.
        $request = new Request(cookies: [$session->getName() => $session->getId()]);
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
            $valkey ?? $this->valkey(),
        );
    }

    private function session(string $id = 'a-session-id'): SessionInterface
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setId($id);

        return $session;
    }

    private function tokenStorage(
        string $userIdentifier,
        string $firewall = 'main',
    ): TokenStorageInterface {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(
            new InMemoryUser(
                $userIdentifier,
                null,
            ),
            $firewall,
        ));

        return $tokenStorage;
    }
}
