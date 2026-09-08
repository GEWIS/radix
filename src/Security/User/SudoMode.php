<?php

declare(strict_types=1);

namespace App\Security\User;

use Psr\Clock\ClockInterface;
use Redis;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

use function count;
use function ctype_digit;
use function explode;
use function hash_equals;
use function is_string;
use function max;

/**
 * Time-bounded "sudo mode" grant, stored in Valkey under the firewall and PHP session it was given on.
 *
 * Granted by the sudo-confirmation flow after the user re-proves identity; either by providing their password or their
 * password + MFA if that is enabled for their account. Checked by {@see SudoVoter} on the 'SUDO' attribute.
 *
 * A grant is held against the firewall it was given on and the account it was given to. One browser holds one PHP
 * session and both firewalls read it, so a single key had a representative confirming their company password unlock
 * the administration for whichever member the same browser was signed in as.
 *
 * The grant has a key of its own instead of sitting on the session because sessions are read and written whole and
 * Valkey does not lock them. A request from a second tab that read the session before the grant was written puts its
 * copy back afterwards, which dropped the grant and returned the user to the prompt they had just answered. A SETEX
 * on a single key cannot be overwritten that way.
 *
 * The key includes the PHP session ID, so a grant is unreachable once that session ends and session.use_strict_mode
 * stops the ID being handed out again.
 */
final class SudoMode
{
    private const string KEY_PREFIX = 'gws_sudo_';

    /** A user identifier can contain a colon, so it goes last and the value is split on the first one only. */
    private const string VALUE_DELIMITER = ':';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'Redis')]
        private readonly Redis $redis,
        private readonly int $ttlSeconds = 1800,
    ) {
    }

    public function isActive(): bool
    {
        return $this->remainingSeconds() > 0;
    }

    public function grant(): void
    {
        $identifier = $this->userIdentifier();
        if (null === $identifier) {
            return;
        }

        $key = $this->key();
        if (null === $key) {
            return;
        }

        // The TTL only removes the key. Whether a grant still counts is decided by the timestamp in the value.
        $this->redis->setex(
            $key,
            $this->ttlSeconds,
            $this->clock->now()->getTimestamp() . self::VALUE_DELIMITER . $identifier,
        );
    }

    public function revoke(): void
    {
        $key = $this->key();

        if (null === $key) {
            return;
        }

        $this->redis->del($key);
    }

    /**
     * Drops the grant of a session other than the one making this request, which is what signing another device out
     * has to do. Without it the grant would outlive the session it was given on by up to its own expiry.
     */
    public function revokeSession(
        Firewall $firewall,
        string $phpSessionId,
    ): void {
        if ('' === $phpSessionId) {
            return;
        }

        $this->redis->del(
            $this->keyFor(
                $firewall->value,
                $phpSessionId,
            ),
        );
    }

    public function remainingSeconds(): int
    {
        $identifier = $this->userIdentifier();
        if (null === $identifier) {
            return 0;
        }

        $key = $this->key();
        if (null === $key) {
            return 0;
        }

        $grant = $this->redis->get($key);
        if (!is_string($grant)) {
            return 0;
        }

        $parts = explode(
            self::VALUE_DELIMITER,
            $grant,
            2,
        );
        if (2 !== count($parts)) {
            return 0;
        }

        [
            $grantedAt, $grantedTo
        ] = $parts;
        if (
            !ctype_digit($grantedAt)
            || !hash_equals(
                $grantedTo,
                $identifier,
            )
        ) {
            return 0;
        }

        return max(
            0,
            (int) $grantedAt + $this->ttlSeconds - $this->clock->now()->getTimestamp(),
        );
    }

    private function key(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (
            null === $request
            || !$request->hasSession()
        ) {
            return null;
        }

        // The API firewall is stateless and has no session to key a grant on.
        $firewall = $this->firewallMap->getFirewallConfig($request)?->getName();
        if (
            null === $firewall
            || null === Firewall::tryFrom($firewall)
        ) {
            return null;
        }

        // An unstarted session has no ID, which would leave grant() doing nothing at all. Callers are signed in and
        // already have one, so in practice this only fires for tests that build the request themselves.
        $session = $request->getSession();
        if (!$session->isStarted()) {
            $session->start();
        }

        $sessionId = $session->getId();
        if ('' === $sessionId) {
            return null;
        }

        return $this->keyFor(
            $firewall,
            $sessionId,
        );
    }

    private function keyFor(
        string $firewall,
        string $phpSessionId,
    ): string {
        return self::KEY_PREFIX . $firewall . '_' . $phpSessionId;
    }

    private function userIdentifier(): ?string
    {
        $token = $this->tokenStorage->getToken();

        // While impersonating, the account on the token is the one being looked at rather than the one that proved
        // itself. The grant belongs to the administrator behind the switch, who is the only party that typed a
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
