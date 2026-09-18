<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\User\ReplayableAction;
use App\Security\User\SudoArea;
use App\Security\User\SudoMode;
use App\Security\User\SudoSession;
use App\Security\User\SudoStash;
use App\Service\Application\FileStorage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Psr\Log\NullLogger;
use Redis;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
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

    /** How long a refused write is kept, as `config/packages/session.yaml` sets it. */
    private const int STASH_TTL_SECONDS = 3600;

    /** The locales `SudoArea` builds its pattern from, as `%app.locales%` provides them. */
    private const string LOCALES = 'en|nl';

    private function sudoMode(
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        string $firewall = 'main',
        ?MockClock $clock = null,
        ?Redis $valkey = null,
    ): SudoMode {
        // One clock for the grant and for the store it lives in, or a key would expire against a different now.
        $clock ??= new MockClock();

        return new SudoMode(
            $clock,
            $this->sudoSession(
                $session,
                $tokenStorage,
                $firewall,
            ),
            $valkey ?? $this->valkey($clock),
            self::IDLE_SECONDS,
            self::ABSOLUTE_SECONDS,
        );
    }

    private function sudoSession(
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        string $firewall = 'main',
    ): SudoSession {
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

        return new SudoSession(
            $requestStack,
            $firewallMap,
            $tokenStorage,
        );
    }

    /**
     * A {@see SudoStash} on the same session as {@see sudoMode()}, because the two are keyed alike and a test that
     * built them apart would not be testing that. The clock is passed on for the same reason: a store with a now of
     * its own keeps a stash alive at the moment the grant beside it expires.
     */
    private function sudoStash(
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        ?FileStorage $storage = null,
        mixed $valkey = null,
        ?MockClock $clock = null,
    ): SudoStash {
        return new SudoStash(
            $this->sudoSession(
                $session,
                $tokenStorage,
            ),
            new SudoArea(self::LOCALES),
            // The router is only read for a path on its own, which the stash never has: it is handed the request
            // the listener refused, and reads the action the router already named on it.
            new ReplayableAction(self::createStub(RouterInterface::class)),
            $storage ?? new FileStorage(new Filesystem(new InMemoryFilesystemAdapter())),
            new NullLogger(),
            $valkey ?? $this->valkey($clock),
            self::STASH_TTL_SECONDS,
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
