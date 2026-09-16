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
 * A {@see SudoMode} with a request of its own, for the tests of the class itself and of the listeners that
 * read and write grants.
 */
trait BuildsSudoMode
{
    use StandsInForValkey;

    /** The two windows, as `config/packages/session.yaml` sets them. */
    private const int IDLE_SECONDS = 1800;
    private const int ABSOLUTE_SECONDS = 7200;

    private function sudoMode(
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        string $firewall = 'main',
        ?MockClock $clock = null,
        ?Redis $valkey = null,
        int $idleSeconds = self::IDLE_SECONDS,
        int $absoluteSeconds = self::ABSOLUTE_SECONDS,
    ): SudoMode {
        // One clock for the grant and for the store it lives in, or a key would expire against a different now.
        $clock ??= new MockClock();

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
            $clock,
            $firewallMap,
            $tokenStorage,
            $valkey ?? $this->valkey($clock),
            $idleSeconds,
            $absoluteSeconds,
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
