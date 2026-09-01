<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\KnownDevice;
use App\Entity\User\KnownDeviceToken;
use App\Entity\User\KnownFact;
use App\Entity\User\KnownNetwork;
use App\Repository\User\KnownDeviceRepository;
use App\Repository\User\KnownDeviceTokenRepository;
use App\Repository\User\KnownFactRepository;
use App\Repository\User\KnownNetworkRepository;
use App\Security\User\DeviceFingerprint;
use App\Security\User\Firewall;
use App\Security\User\UrlSafeToken;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function count;
use function hash_hmac;
use function is_string;

/**
 * Which devices an account has signed in from before, and therefore which sign-ins are worth writing to somebody
 * about. A sign-in is recognised when the cookie handed out at an earlier sign-in comes back, or when the device kind
 * and the network are both already known; device and network are learned apart, or a member who moves between home
 * and campus would be announced on every pairing of the two.
 *
 * Recognition is only ever a reason to withhold a notice. It grants nothing, it is consulted after the sign-in has
 * already succeeded, and every failure here returns false, so a device that goes unrecognised means the member is
 * told.
 */
final readonly class KnownDeviceRegistry
{
    /**
     * Longer than the longest remember-me cookie in `config/packages/session.yaml` on purpose: were the two the same
     * length, the forced re-login at the end of a ridden-out cookie would land just past the boundary and announce
     * the machine the member never left.
     */
    public const string RETENTION = '-120 days';

    /**
     * Where {@see self::recognise()} leaves the device cookie for
     * {@see \App\EventListener\User\KnownDeviceCookieListener} to put on the response.
     */
    public const string COOKIE_ATTRIBUTE = '_app_known_device_cookie';

    /** Longer than {@see self::RETENTION}; the row's clock is what decides, the cookie only names the row. */
    private const string COOKIE_LIFETIME = '+365 days';

    private const string REFRESH_THROTTLE = '-1 day';

    /**
     * Caps each fact kind per account and firewall, so a password in the wrong hands cannot fill the table with
     * entries that suppress everything after them.
     */
    private const int LIMIT = 20;

    private const string TOKEN_HMAC_ALGO = 'sha256';

    public function __construct(
        private KnownDeviceRepository $devices,
        private KnownNetworkRepository $networks,
        private KnownDeviceTokenRepository $tokens,
        private DeviceFingerprint $fingerprint,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire(param: 'kernel.secret')]
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * Whether this device has signed in to this account before, recording it either way. False means the member
     * should be told.
     */
    public function recognise(
        string $userIdentifier,
        string $firewallName,
        Request $request,
    ): bool {
        try {
            $firewall = Firewall::tryFrom($firewallName);
            $described = $this->fingerprint->describe($request);
            $now = $this->clock->now();
            $freshSince = $now->modify(self::RETENTION);

            $presented = null !== $firewall ? $this->presentedCookie(
                $request,
                $firewall,
            ) : null;
            $token = null !== $presented ? $this->tokens->findOneByTokenHash(
                $userIdentifier,
                $firewallName,
                $this->hashToken($presented),
            ) : null;

            $recognised = null !== $token && $token->getLastSeenAt() > $freshSince;

            $device = $this->devices->findOneByFingerprint(
                $userIdentifier,
                $firewallName,
                $described['device'],
            );
            $deviceKnown = null !== $device && $device->getLastSeenAt() > $freshSince;

            if (null === $device) {
                $this->evictToFit(
                    $this->devices,
                    $userIdentifier,
                    $firewallName,
                );

                $device = new KnownDevice();
                $device->setUserIdentifier($userIdentifier);
                $device->setFirewallName($firewallName);
                $device->setFingerprint($described['device']);
                $device->setFirstSeenAt($now);

                $this->entityManager->persist($device);
            }

            // The versions are display only, so they follow the browser even though they are no part of the key.
            $device->setBrowser($described['browser']);
            $device->setOperatingSystem($described['operatingSystem']);
            $device->setLastSeenAt($now);

            if ([] !== $described['networks']) {
                // Always evaluated: the networks must be learned even on a sign-in something else already vouches
                // for.
                $networksKnown = $this->recogniseNetworks(
                    $userIdentifier,
                    $firewallName,
                    $described['networks'],
                    $now,
                );
                $recognised = $recognised || ($deviceKnown && $networksKnown);
            }

            if (null !== $token) {
                $token->setBrowser($described['browser']);
                $token->setOperatingSystem($described['operatingSystem']);
                $token->setLastSeenAt($now);
                $issue = $presented;
            } elseif (null !== $firewall) {
                $this->evictToFit(
                    $this->tokens,
                    $userIdentifier,
                    $firewallName,
                );

                $issue = UrlSafeToken::generate();

                $token = new KnownDeviceToken();
                $token->setUserIdentifier($userIdentifier);
                $token->setFirewallName($firewallName);
                $token->setTokenHash($this->hashToken($issue));
                $token->setBrowser($described['browser']);
                $token->setOperatingSystem($described['operatingSystem']);
                $token->setFirstSeenAt($now);
                $token->setLastSeenAt($now);

                $this->entityManager->persist($token);
            } else {
                $issue = null;
            }

            $this->entityManager->flush();

            // Only after the flush: a cookie whose row was never written would name nothing. Re-issued on a match so
            // the year the browser holds it counts from the last sign-in rather than the first.
            if (
                null !== $firewall
                && null !== $issue
            ) {
                $this->issueCookie(
                    $request,
                    $firewall,
                    $issue,
                    $now,
                );
            }

            return $recognised;
        } catch (Throwable $e) {
            $this->logger->warning(
                'Could not tell whether this device is a known one.',
                [
                    'user' => $userIdentifier,
                    'firewall' => $firewallName,
                    'exception' => $e,
                ],
            );

            return false;
        }
    }

    /**
     * Note that the facts this account is already recognised on are still being seen. {@see self::recognise()} only
     * runs at password sign-ins, so without this a member who stays signed in for months would be announced on the
     * machine they never stopped using. Nothing is created and nothing is revived: a lapsed fact has a notice owing
     * on it.
     */
    public function refresh(
        string $userIdentifier,
        string $firewallName,
        Request $request,
    ): void {
        try {
            $described = $this->fingerprint->describe($request);

            $facts = [
                $this->devices->findOneByFingerprint(
                    $userIdentifier,
                    $firewallName,
                    $described['device'],
                ),
            ];

            foreach ($described['networks'] as $fingerprint) {
                $facts[] = $this->networks->findOneByFingerprint(
                    $userIdentifier,
                    $firewallName,
                    $fingerprint,
                );
            }

            $firewall = Firewall::tryFrom($firewallName);
            $presented = null !== $firewall ? $this->presentedCookie(
                $request,
                $firewall,
            ) : null;

            if (null !== $presented) {
                $facts[] = $this->tokens->findOneByTokenHash(
                    $userIdentifier,
                    $firewallName,
                    $this->hashToken($presented),
                );
            }

            $now = $this->clock->now();
            $changed = false;

            foreach ($facts as $fact) {
                if (null === $fact) {
                    continue;
                }

                $lastSeenAt = $fact->getLastSeenAt();

                if (
                    $lastSeenAt <= $now->modify(self::RETENTION)
                    || $lastSeenAt > $now->modify(self::REFRESH_THROTTLE)
                ) {
                    continue;
                }

                $fact->setLastSeenAt($now);
                $changed = true;
            }

            if ($changed) {
                $this->entityManager->flush();
            }
        } catch (Throwable $e) {
            // On the request path of everybody signed in: an un-refreshed fact costs a needless notice, an exception
            // would end the session.
            $this->logger->warning(
                'Could not note that this device is still in use.',
                [
                    'user' => $userIdentifier,
                    'firewall' => $firewallName,
                    'exception' => $e,
                ],
            );
        }
    }

    /**
     * Forget everything recognition rests on for an account, so the next sign-in from anywhere is announced. Called
     * when the way into the account changes; one transaction, because a partial forget would leave an intruder's
     * facts trusted across the very reset meant to end them.
     */
    public function forget(
        string $userIdentifier,
        string $firewallName,
    ): void {
        $connection = $this->entityManager->getConnection();

        try {
            $connection->beginTransaction();

            try {
                $this->devices->deleteAllForUserOnFirewall(
                    $userIdentifier,
                    $firewallName,
                );
                $this->networks->deleteAllForUserOnFirewall(
                    $userIdentifier,
                    $firewallName,
                );
                $this->tokens->deleteAllForUserOnFirewall(
                    $userIdentifier,
                    $firewallName,
                );

                $connection->commit();
            } catch (Throwable $e) {
                $connection->rollBack();

                throw $e;
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Could not forget the known devices for an account.',
                [
                    'user' => $userIdentifier,
                    'firewall' => $firewallName,
                    'exception' => $e,
                ],
            );
        }
    }

    /**
     * Whether any name for the current network is already known, learning every name either way. Learning them all
     * is what keeps recognition steady when the ASN database first arrives, disappears with a rebuilt volume, or
     * comes back: whichever name was on file when a network was learned keeps answering.
     *
     * @param non-empty-list<string> $fingerprints
     */
    private function recogniseNetworks(
        string $userIdentifier,
        string $firewallName,
        array $fingerprints,
        DateTimeImmutable $now,
    ): bool {
        $known = false;
        $missing = [];

        foreach ($fingerprints as $fingerprint) {
            $network = $this->networks->findOneByFingerprint(
                $userIdentifier,
                $firewallName,
                $fingerprint,
            );

            if (null === $network) {
                $missing[] = $fingerprint;

                continue;
            }

            $known = $known || $network->getLastSeenAt() > $now->modify(self::RETENTION);
            $network->setLastSeenAt($now);
        }

        if ([] !== $missing) {
            $this->evictToFit(
                $this->networks,
                $userIdentifier,
                $firewallName,
                count($missing),
            );

            foreach ($missing as $fingerprint) {
                $network = new KnownNetwork();
                $network->setUserIdentifier($userIdentifier);
                $network->setFirewallName($firewallName);
                $network->setFingerprint($fingerprint);
                $network->setFirstSeenAt($now);
                $network->setLastSeenAt($now);

                $this->entityManager->persist($network);
            }
        }

        return $known;
    }

    /**
     * @param KnownFactRepository<covariant KnownFact> $repository
     */
    private function evictToFit(
        KnownFactRepository $repository,
        string $userIdentifier,
        string $firewallName,
        int $incoming = 1,
    ): void {
        $surplus = $repository->countForUserOnFirewall(
            $userIdentifier,
            $firewallName,
        ) + $incoming - self::LIMIT;

        if ($surplus <= 0) {
            return;
        }

        foreach (
            $repository->findLeastRecentlySeen(
                $userIdentifier,
                $firewallName,
                $surplus,
            ) as $fact
        ) {
            $this->entityManager->remove($fact);
        }
    }

    private function presentedCookie(
        Request $request,
        Firewall $firewall,
    ): ?string {
        $value = $request->cookies->get($firewall->deviceCookieName());

        return is_string($value) && '' !== $value
            ? $value
            : null;
    }

    /**
     * Left on the request for {@see \App\EventListener\User\KnownDeviceCookieListener}, because recognition runs
     * inside the remember-me handler and holds no response.
     */
    private function issueCookie(
        Request $request,
        Firewall $firewall,
        string $value,
        DateTimeImmutable $now,
    ): void {
        $request->attributes->set(
            self::COOKIE_ATTRIBUTE,
            Cookie::create($firewall->deviceCookieName())
                ->withValue($value)
                ->withExpires($now->modify(self::COOKIE_LIFETIME))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );
    }

    /**
     * Only ever the hash is stored, so reading the table does not yield cookies that would quiet somebody's notices.
     */
    private function hashToken(#[SensitiveParameter]
    string $value,): string
    {
        return hash_hmac(
            self::TOKEN_HMAC_ALGO,
            $value,
            $this->secret,
        );
    }
}
