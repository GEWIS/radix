<?php

declare(strict_types=1);

namespace App\Security\User;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function hash_equals;
use function is_array;
use function is_int;
use function is_string;
use function max;

/**
 * Time-bounded "sudo mode" grant, stored on the PHP session.
 *
 * Granted by the sudo-confirmation flow after the user re-proves identity; either by providing their password or their
 * password + MFA if that is enabled for their account. Checked by {@see SudoVoter} on the 'SUDO' attribute.
 *
 * A grant is held against the firewall it was given on and the account it was given to. One browser holds one PHP
 * session and both firewalls read it, so a single key had a representative confirming their company password unlock
 * the administration for whichever member the same browser was signed in as.
 */
final class SudoMode
{
    /** Underscore prefix matches Symfony's `_security_*`, which is likewise per firewall. */
    private const string SESSION_KEY_PREFIX = '_sudo_granted_at_';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly int $ttlSeconds = 1800,
    ) {
    }

    public function isActive(): bool
    {
        return $this->remainingSeconds() > 0;
    }

    public function grant(): void
    {
        $key = $this->sessionKey();
        $identifier = $this->userIdentifier();

        if (
            null === $key
            || null === $identifier
        ) {
            return;
        }

        $this->requestStack->getSession()->set(
            $key,
            [
                'grantedAt' => $this->clock->now()->getTimestamp(),
                'userIdentifier' => $identifier,
            ],
        );
    }

    public function revoke(): void
    {
        $key = $this->sessionKey();

        if (null === $key) {
            return;
        }

        $this->requestStack->getSession()->remove($key);
    }

    public function remainingSeconds(): int
    {
        $request = $this->requestStack->getMainRequest();
        if (
            null === $request
            || !$request->hasPreviousSession()
        ) {
            return 0;
        }

        $key = $this->sessionKey();
        $identifier = $this->userIdentifier();
        if (
            null === $key
            || null === $identifier
        ) {
            return 0;
        }

        $grant = $request->getSession()->get($key);
        if (!is_array($grant)) {
            return 0;
        }

        $grantedAt = $grant['grantedAt'] ?? null;
        $grantedTo = $grant['userIdentifier'] ?? null;
        if (
            !is_int($grantedAt)
            || !is_string($grantedTo)
            || !hash_equals(
                $grantedTo,
                $identifier,
            )
        ) {
            return 0;
        }

        return max(
            0,
            $grantedAt + $this->ttlSeconds - $this->clock->now()->getTimestamp(),
        );
    }

    private function sessionKey(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        $firewall = $this->firewallMap->getFirewallConfig($request)?->getName();
        if (null === $firewall) {
            return null;
        }

        return self::SESSION_KEY_PREFIX . $firewall;
    }

    private function userIdentifier(): ?string
    {
        $identifier = $this->tokenStorage->getToken()?->getUserIdentifier();

        if (
            null === $identifier
            || '' === $identifier
        ) {
            return null;
        }

        return $identifier;
    }
}
