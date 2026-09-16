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
use function min;

/**
 * Time-bounded "sudo mode" grant, stored in Valkey under the firewall and PHP session it was given on.
 *
 * Two expirations. The first is extended by every guarded write, so a long edit is not interrupted by a prompt part
 * of the way through it; a GET does not extend it, so an idle page still expires. The second is measured from the
 * confirmation and is never extended, so a browser in use all day still requires a new confirmation.
 *
 * Granted by the sudo-confirmation flow after the user re-proves identity; either by providing their password or their
 * password + MFA if that is enabled for their account. Checked by {@see SudoVoter} on the 'SUDO' attribute.
 *
 * A grant is scoped to the firewall it was given on and the account it was given to. One browser has one PHP session
 * and both firewalls read it, so a single key had a representative confirming their company password unlock the
 * administration for whichever member the same browser was signed in as.
 *
 * The grant has a key of its own instead of being stored on the session because sessions are read and written whole
 * and Valkey does not lock them. A request from a second tab that read the session before the grant was written puts
 * its copy back afterwards, which dropped the grant and returned the user to the prompt they had just completed. A
 * SETEX on a single key cannot be overwritten that way.
 *
 * The key includes the PHP session ID, so a grant is unreachable once that session ends and session.use_strict_mode
 * stops the ID being issued again.
 */
final class SudoMode
{
    private const string KEY_PREFIX = 'gws_sudo_';

    /** A user identifier can contain a colon, so it goes last and the value is split into three parts only. */
    private const string VALUE_DELIMITER = ':';

    /** The fields of the stored value, in order: the confirmation timestamp, the last extension, the identifier. */
    private const int VALUE_PARTS = 3;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'security.firewall.map')]
        private readonly FirewallMap $firewallMap,
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'Redis')]
        private readonly Redis $redis,
        #[Autowire(param: 'app.sudo_idle_ttl')]
        private readonly int $idleSeconds,
        #[Autowire(param: 'app.sudo_absolute_ttl')]
        private readonly int $absoluteSeconds,
    ) {
    }

    public function isActive(): bool
    {
        return $this->remainingSeconds() > 0;
    }

    public function grant(): void
    {
        $now = $this->clock->now()->getTimestamp();

        $this->write(
            $now,
            $now,
        );
    }

    /**
     * Extends the first expiration, leaving the confirmation timestamp unchanged.
     *
     * Called only for a guarded write. A GET does not extend it, because a component that re-renders on a timer would
     * otherwise keep a grant valid without user interaction.
     *
     * An expired grant is not revived, and nothing is written where there is no grant: this runs on every
     * authenticated write, and would otherwise create a key for a user who never confirmed.
     */
    public function touch(): void
    {
        $grant = $this->read();
        if (null === $grant) {
            return;
        }

        [
            $grantedAt,
        ] = $grant;

        $now = $this->clock->now()->getTimestamp();
        if ($now >= $this->expiresAt($grant)) {
            return;
        }

        $this->write(
            $grantedAt,
            $now,
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

    /**
     * The seconds until the earlier of the two expirations.
     */
    public function remainingSeconds(): int
    {
        $grant = $this->read();
        if (null === $grant) {
            return 0;
        }

        return max(
            0,
            $this->expiresAt($grant) - $this->clock->now()->getTimestamp(),
        );
    }

    /**
     * The earlier of the two expirations, which is the one that decides the grant.
     *
     * @param array{int, int} $grant
     */
    private function expiresAt(array $grant): int
    {
        [
            $grantedAt, $lastActiveAt
        ] = $grant;

        return min(
            $lastActiveAt + $this->idleSeconds,
            $grantedAt + $this->absoluteSeconds,
        );
    }

    /**
     * The two timestamps of this session's grant, or null when there is no valid grant.
     *
     * @return array{int, int}|null
     */
    private function read(): ?array
    {
        $identifier = $this->userIdentifier();
        if (null === $identifier) {
            return null;
        }

        $key = $this->key();
        if (null === $key) {
            return null;
        }

        $grant = $this->redis->get($key);
        if (!is_string($grant)) {
            return null;
        }

        $parts = explode(
            self::VALUE_DELIMITER,
            $grant,
            self::VALUE_PARTS,
        );
        if (self::VALUE_PARTS !== count($parts)) {
            return null;
        }

        [
            $grantedAt,
            $lastActiveAt, $grantedTo
        ] = $parts;
        if (
            !ctype_digit($grantedAt)
            || !ctype_digit($lastActiveAt)
            || !hash_equals(
                $grantedTo,
                $identifier,
            )
        ) {
            return null;
        }

        return [
            (int) $grantedAt,
            (int) $lastActiveAt,
        ];
    }

    private function write(
        int $grantedAt,
        int $lastActiveAt,
    ): void {
        $identifier = $this->userIdentifier();
        if (null === $identifier) {
            return;
        }

        $key = $this->key();
        if (null === $key) {
            return;
        }

        // The key expiration is the upper bound; validity is decided by the timestamps in the value. It is derived
        // from the second expiration, so a grant extended late in its life is still removed on time.
        $this->redis->setex(
            $key,
            max(
                1,
                $grantedAt + $this->absoluteSeconds - $this->clock->now()->getTimestamp(),
            ),
            $grantedAt . self::VALUE_DELIMITER . $lastActiveAt . self::VALUE_DELIMITER . $identifier,
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

        // While impersonating, the account on the token is the impersonated one rather than the one that authenticated.
        // The grant belongs to the administrator behind the switch, who is the only one that entered a password, so
        // read through to them.
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
