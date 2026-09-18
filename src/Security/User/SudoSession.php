<?php

declare(strict_types=1);

namespace App\Security\User;

use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

/**
 * What a sudo grant, and everything stored beside it, is keyed by: the firewall the request matches and the PHP
 * session it was made on.
 *
 * Extracted from {@see SudoMode} because {@see SudoStash} is keyed the same way and the two must agree. A stash that
 * outlived the session of the grant it belongs to, or that a second account on the same browser could read, would
 * undo what the scoping is for.
 */
final readonly class SudoSession
{
    public function __construct(
        private RequestStack $requestStack,
        #[Autowire(service: 'security.firewall.map')]
        private FirewallMap $firewallMap,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * The firewall and session of the request being handled, or null where there is nothing to key on.
     */
    public function current(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (
            null === $request
            || !$request->hasSession()
        ) {
            return null;
        }

        // The API firewall is stateless and has no session to key on.
        $name = $this->firewallMap->getFirewallConfig($request)?->getName();
        if (null === $name) {
            return null;
        }

        $firewall = Firewall::tryFrom($name);
        if (null === $firewall) {
            return null;
        }

        // An unstarted session has no ID, which would leave a caller writing nothing at all. Callers are signed in
        // and already have one, so in practice this only applies to tests that build the request themselves.
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $sessionId = $session->getId();
        if ('' === $sessionId) {
            return null;
        }

        return $this->of(
            $firewall,
            $sessionId,
        );
    }

    public function of(
        Firewall $firewall,
        string $phpSessionId,
    ): string {
        return $firewall->value . '_' . $phpSessionId;
    }

    public function userIdentifier(): ?string
    {
        $token = $this->tokenStorage->getToken();

        // While impersonating, the account on the token is the impersonated one rather than the one that
        // authenticated. A grant belongs to the administrator behind the switch, who is the only one that entered a
        // password, so read through to them.
        while ($token instanceof SwitchUserToken) {
            $token = $token->getOriginalToken();
        }

        $identifier = $token?->getUserIdentifier();

        if (
            null === $identifier
            || '' === $identifier
        ) {
            return null;
        }

        return $identifier;
    }
}
