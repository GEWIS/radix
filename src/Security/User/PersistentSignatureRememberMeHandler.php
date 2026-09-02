<?php

declare(strict_types=1);

namespace App\Security\User;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\User\Session;
use App\Repository\User\SessionRepository;
use App\Service\User\KnownDeviceRegistry;
use App\Service\User\SecurityNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CookieTheftException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\RememberMe\AbstractRememberMeHandler;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Throwable;

use function count;
use function explode;
use function hash;
use function hash_equals;
use function sprintf;
use function trim;

/**
 * Combines the best of Symfony's two built-in remember-me handlers:
 *
 * - {@see \Symfony\Component\Security\Http\RememberMe\SignatureRememberMeHandler}: HMAC-signed tokens, invalidates on
 *   property change
 * - {@see \Symfony\Component\Security\Http\RememberMe\PersistentRememberMeHandler}: revocable per-device, cookie-theft
 *   detection, and rich session metadata
 */
class PersistentSignatureRememberMeHandler extends AbstractRememberMeHandler
{
    private const string COOKIE_DELIMITER = ':';
    private const string HASH_ALGO = 'sha256';

    /** Tabs woken together present the same token; without this, every one but the first looks like a replay. */
    private const int TOKEN_GRACE_SECONDS = 60;

    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        UserProviderInterface $userProvider,
        RequestStack $requestStack,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly SessionRepository $repository,
        private readonly UserAgentParser $userAgentParser,
        private readonly SecurityNotifier $securityNotifier,
        private readonly KnownDeviceRegistry $knownDevices,
        private readonly ClockInterface $clock,
        private readonly CredentialsSignature $credentials,
        private readonly SessionRowSignature $rowSignature,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        string $secret,
        private readonly string $firewallName,
        private readonly int $tokenLifetime,
        string $cookieName,
        ?LoggerInterface $logger = null,
    ) {
        if ('' === trim($secret)) {
            throw new RuntimeException(sprintf(
                'kernel.secret is empty for firewall "%s". Set APP_SECRET in your .env file.',
                $firewallName,
            ));
        }

        parent::__construct(
            $userProvider,
            $requestStack,
            [
                'name' => $cookieName,
                'lifetime' => $tokenLifetime,
                'secure' => null,
                // Required to be `lax`, otherwise user is always logged out on cross-origin requests.
                'samesite' => Cookie::SAMESITE_LAX,
            ],
            $logger,
        );
    }

    #[Override]
    public function createRememberMeCookie(UserInterface $user): void
    {
        $request = $this->requestStack->getMainRequest();

        if (null === $request) {
            $this->logger?->warning(
                'createRememberMeCookie called without an active request.',
                [
                    'firewall' => $this->firewallName,
                ],
            );

            return;
        }

        [
            $series,
            $rawToken, $expiresAt
        ] = $this->createSession(
            $user,
            $request,
        );

        $this->createCookie(new RememberMeDetails(
            $user->getUserIdentifier(),
            $expiresAt->getTimestamp(),
            $series . self::COOKIE_DELIMITER . $rawToken,
        ));
    }

    /**
     * Verifies a remember-me cookie against the persisted session row, then
     * rotates the token. Invoked by {@see AbstractRememberMeHandler::consumeRememberMeCookie()}
     * after the user has been loaded via the user provider.
     */
    #[Override]
    protected function processRememberMe(
        RememberMeDetails $rememberMeDetails,
        UserInterface $user,
    ): void {
        $parts = explode(
            self::COOKIE_DELIMITER,
            $rememberMeDetails->getValue(),
            2,
        );

        if (2 !== count($parts)) {
            throw new AuthenticationException('Malformed remember-me cookie value.');
        }

        [
            $series, $rawToken
        ] = $parts;
        $session = $this->repository->findOneBySeries($series);

        if (null === $session) {
            throw new AuthenticationException('Remember-me series not found in storage.');
        }

        // Ensure we only process remember-me requests for the correct firewall.
        if ($session->getFirewallName() !== $this->firewallName) {
            $this->logger?->warning(
                'Cross-firewall token replay attempt rejected.',
                [
                    'series' => $series,
                    'token_firewall' => $session->getFirewallName(),
                    'request_firewall' => $this->firewallName,
                ],
            );

            throw new AuthenticationException('Remember-me token does not belong to this firewall.');
        }

        // The remember-me token must not be expired.
        if ($session->isExpired()) {
            $this->entityManager->remove($session);
            $this->entityManager->flush();

            throw new AuthenticationException('Remember-me token has expired.');
        }

        // Ensure integrity of the remember-me token.
        if (!$this->rowSignature->verify($session)) {
            $this->logger?->warning(
                'HMAC mismatch; possible DB tampering or kernel.secret rotation.',
                [
                    'series' => $series,
                    'user' => $session->getUserIdentifier(),
                    'firewall' => $this->firewallName,
                ],
            );
            $this->entityManager->remove($session);
            $this->entityManager->flush();

            throw new AuthenticationException('Remember-me signature is invalid.');
        }

        $presentedToken = hash(
            self::HASH_ALGO,
            $rawToken,
        );

        $raced = !hash_equals(
            $session->getHashedToken(),
            $presentedToken,
        );

        if ($raced) {
            // A token this row has never held is forged rather than replayed. Reading it as theft would let anybody
            // who learns a series sign the account out of every device by presenting rubbish alongside it.
            if (
                !hash_equals(
                    $session->getPreviousHashedToken() ?? '',
                    $presentedToken,
                )
            ) {
                throw new AuthenticationException('Remember-me token is not recognised.');
            }

            // The token this row just replaced, presented past the window a second tab could still be holding it in.
            if (!$this->withinGracePeriod($session->getPreviousTokenValidUntil())) {
                $this->reportTheft($session);
            }
        }

        // If any of the properties in the signature changed, detect that and force log out.
        if (
            !$this->credentials->matches(
                $session->getSignaturePropertiesHash(),
                $user,
            )
        ) {
            $this->logger?->info(
                'User properties fingerprint changed – session invalidated.',
                [
                    'series' => $series,
                    'user' => $session->getUserIdentifier(),
                    'firewall' => $this->firewallName,
                    'properties' => CredentialsSignature::PROPERTIES,
                ],
            );
            $this->entityManager->remove($session);
            $this->entityManager->flush();
            $this->createCookie(null);

            throw new AuthenticationException(
                'Remember-me session invalidated: user security properties have changed.',
            );
        }

        // Setting a cookie here would strand the one the winner already handed the browser.
        if ($raced) {
            $this->logger?->debug(
                'Remember-me token was rotated by a concurrent request; accepted within the grace period.',
                [
                    'series' => $series,
                    'user' => $session->getUserIdentifier(),
                    'firewall' => $this->firewallName,
                ],
            );

            return;
        }

        // Rotate the token on every successful use. This is what makes the theft check above meaningful: an old raw
        // token reappearing after the grace period is the smoking gun. Without rotation, we could not distinguish
        // legitimate reuse from a replayed stolen cookie.
        $newRawToken = UrlSafeToken::generate();
        $newHashedToken = hash(
            self::HASH_ALGO,
            $newRawToken,
        );
        $now = $this->clock->now();
        $validUntil = $now->modify('+' . self::TOKEN_GRACE_SECONDS . ' seconds');

        $rotated = $this->repository->rotateToken(
            $session->getSeries(),
            $session->getHashedToken(),
            $newHashedToken,
            $this->rowSignature->forRotation(
                $session,
                $newHashedToken,
                $validUntil,
            ),
            $validUntil,
            $now,
        );

        // Lost the write, so what this request holds has just become the previous token.
        if (!$rotated) {
            $grace = $this->repository->findRotationGrace($session->getSeries());

            if (
                !hash_equals(
                    $grace['previousHashedToken'] ?? '',
                    $presentedToken,
                )
                || !$this->withinGracePeriod($grace['previousTokenValidUntil'] ?? null)
            ) {
                $this->reportTheft($session);
            }

            return;
        }

        $this->createCookie(new RememberMeDetails(
            $user->getUserIdentifier(),
            $session->getExpiresAt()->getTimestamp(),
            $series . self::COOKIE_DELIMITER . $newRawToken,
        ));
    }

    private function withinGracePeriod(?DateTimeImmutable $validUntil): bool
    {
        return null !== $validUntil
            && $validUntil > $this->clock->now();
    }

    /** @throws CookieTheftException Always. */
    private function reportTheft(Session $session): never
    {
        $this->logger?->emergency(
            'Cookie theft detected! Invalidating ALL sessions for this user on this firewall.',
            [
                'series' => $session->getSeries(),
                'user' => $session->getUserIdentifier(),
                'firewall' => $this->firewallName,
            ],
        );
        $this->repository->deleteAllForUserOnFirewall(
            $session->getUserIdentifier(),
            $this->firewallName,
        );
        $this->entityManager->flush();

        throw new CookieTheftException(
            'Remember-me token was already consumed. All sessions on this firewall have been invalidated.',
        );
    }

    #[Override]
    public function clearRememberMeCookie(): void
    {
        $request = $this->requestStack->getMainRequest();

        if (null !== $request) {
            $series = $this->getSeriesFromCookie($request);

            if (null !== $series) {
                $session = $this->repository->findOneBySeries($series);

                if (
                    null !== $session
                    && $session->getFirewallName() === $this->firewallName
                ) {
                    $this->entityManager->remove($session);
                    $this->entityManager->flush();
                }
            }
        }

        parent::clearRememberMeCookie();
    }

    public function getSeriesFromCookie(Request $request): ?string
    {
        $cookieValue = $request->cookies->get($this->options['name']);

        if (
            null === $cookieValue
            || '' === $cookieValue
        ) {
            return null;
        }

        try {
            $details = RememberMeDetails::fromRawCookie($cookieValue);
            $parts = explode(
                self::COOKIE_DELIMITER,
                $details->getValue(),
                2,
            );

            // Returning the whole value as a series would walk past the guards that check for the absence of one.
            if (2 !== count($parts)) {
                return null;
            }

            return $parts[0];
        } catch (Throwable) {
            return null;
        }
    }

    public function getFirewallName(): string
    {
        return $this->firewallName;
    }

    /** @return array{0: string, 1: string, 2: DateTimeImmutable} [series, rawToken, expiresAt] */
    private function createSession(
        UserInterface $user,
        Request $request,
    ): array {
        $series = UrlSafeToken::generate(44);
        $rawToken = UrlSafeToken::generate();
        $now = $this->clock->now();
        $expiresAt = $now->modify('+' . $this->tokenLifetime . ' seconds');

        $hashedToken = hash(
            self::HASH_ALGO,
            $rawToken,
        );

        $userAgent = $request->headers->get(
            'User-Agent',
            '',
        );
        $meta = $this->userAgentParser->parseRequest($request);

        $session = new Session();
        $session->setSeries($series);
        $session->setHashedToken($hashedToken);
        $session->setSignaturePropertiesHash($this->credentials->hash($user));
        $session->setFirewallName($this->firewallName);
        $session->setUserIdentifier($user->getUserIdentifier());
        $session->setCreatedAt($now);
        $session->setExpiresAt($expiresAt);
        $session->setLastUsedAt($now);
        $session->setUserAgent($userAgent);
        $session->setIpAddress($request->getClientIp() ?? '');
        $session->setPhpSessionId($request->getSession()->getId());
        $session->setDeviceType($meta['type']);
        $session->setBrowser($meta['browser']);
        $session->setOperatingSystem($meta['operatingSystem']);
        // Last: the signature covers every field above.
        $session->setSignature($this->rowSignature->forRow($session));

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $this->announceSignIn(
            $user->getUserIdentifier(),
            $request,
        );

        return [
            $series,
            $rawToken,
            $expiresAt,
        ];
    }

    /**
     * A sign-in is announced unless it came from a device this account has signed in from before.
     *
     * Only this notice is ever withheld. A changed password or a second factor turned off goes out whatever device it
     * came from, which is why the decision sits here rather than inside {@see SecurityNotifier}, where it could
     * quietly come to cover them too. {@see \App\Service\User\KnownDeviceRegistry} sets out what recognition rests
     * on and how weak it is.
     */
    private function announceSignIn(
        string $userIdentifier,
        Request $request,
    ): void {
        $firewall = Firewall::tryFrom($this->firewallName);

        if (null === $firewall) {
            return;
        }

        if (
            $this->knownDevices->recognise(
                $userIdentifier,
                $this->firewallName,
                $request,
            )
        ) {
            return;
        }

        $this->securityNotifier->notify(
            $firewall,
            $userIdentifier,
            NotificationType::SignIn,
            $request,
        );
    }
}
