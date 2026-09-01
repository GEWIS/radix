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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function base64_encode;
use function hash_hmac;
use function is_string;
use function random_bytes;
use function rtrim;
use function strtr;

/**
 * Which devices an account has signed in from before, and therefore which sign-ins are worth writing to somebody
 * about.
 *
 * Recognition rests on three facts learned independently, because no one of them survives how our members actually
 * live:
 *
 *  - a cookie handed to the browser at sign-in ({@see KnownDeviceToken}), the one exact answer, gone the moment the
 *    member works in a private window;
 *  - what kind of device this is ({@see KnownDevice}), which a private window cannot take off a browser, but which is
 *    shared with everybody on the same browser and system;
 *  - the network it came from ({@see KnownNetwork}), which members rotate through daily between home, campus and
 *    their phone.
 *
 * A sign-in is recognised when the cookie is, or when the device and the network both are. Keeping device and network
 * apart is the point: were they one key, every pairing of a laptop with a network would be announced as new, and
 * a member who moves between home and university would be written to for the rest of their membership.
 *
 * Recognition is only ever a reason to withhold a notice. It grants nothing, it is consulted after the sign-in has
 * already succeeded, and every failure here returns false, so a device that goes unrecognised means the member is
 * told.
 */
final readonly class KnownDeviceRegistry
{
    /**
     * How long a fact stays recognised without being seen. Refreshed on activity by {@see self::refresh()}, so a
     * device somebody actually uses never lapses.
     *
     * Longer than the longest remember-me cookie in `config/packages/session.yaml` on purpose. Were the two the same
     * length, somebody who signed in once and rode that cookie until it ran out would be told about a new device on
     * the machine they had been on all along, because the sign-in they are forced into lands on the far side of the
     * boundary.
     */
    public const string RETENTION = '-120 days';

    /**
     * Where {@see self::recognise()} leaves the device cookie for
     * {@see \App\EventListener\User\KnownDeviceCookieListener} to put on the response, the same relay Symfony's
     * remember-me uses for its own cookie.
     */
    public const string COOKIE_ATTRIBUTE = '_app_known_device_cookie';

    /**
     * How long the browser holds the device cookie. Longer than {@see self::RETENTION}: the cookie only means
     * anything while its row is alive, and the row's clock is what decides. Re-issued at every sign-in, so only a
     * browser that stays away over a year loses it, at which point the device and network fingerprints still carry
     * the recognition.
     */
    private const string COOKIE_LIFETIME = '+365 days';

    /**
     * How long a fact's last-seen is left alone while it is in use. Activity arrives far more often than it is worth
     * writing down, and all this timestamp decides is whether a sign-in months from now is announced.
     */
    private const string REFRESH_THROTTLE = '-1 day';

    /**
     * The most facts of one kind kept for one account on one firewall, so that a password in the wrong hands cannot
     * fill the table with entries that suppress everything after them. Eviction is least-recently-seen, which also
     * clears out the tokens minted for private windows that never presented them again.
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
     * Whether this device has signed in to this account before, recording it either way.
     *
     * False means the member should be told: nothing on the request was recent enough to vouch for it, either because
     * the device or its network is new, or because they were last seen longer ago than {@see self::RETENTION}.
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

            $presented = null;
            $token = null;

            if (null !== $firewall) {
                $presented = $request->cookies->get($firewall->deviceCookieName());

                if (
                    is_string($presented)
                    && '' !== $presented
                ) {
                    $token = $this->tokens->findOneByTokenHash(
                        $userIdentifier,
                        $firewallName,
                        $this->hashToken($presented),
                    );
                }
            }

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

            // The versions are what a member is shown, so they follow the browser even though they are no part of
            // the key.
            $device->setBrowser($described['browser']);
            $device->setOperatingSystem($described['operatingSystem']);
            $device->setLastSeenAt($now);

            if (null !== $described['network']) {
                $network = $this->networks->findOneByFingerprint(
                    $userIdentifier,
                    $firewallName,
                    $described['network'],
                );
                $networkKnown = null !== $network && $network->getLastSeenAt() > $freshSince;

                if (null === $network) {
                    $this->evictToFit(
                        $this->networks,
                        $userIdentifier,
                        $firewallName,
                    );

                    $network = new KnownNetwork();
                    $network->setUserIdentifier($userIdentifier);
                    $network->setFirewallName($firewallName);
                    $network->setFingerprint($described['network']);
                    $network->setFirstSeenAt($now);

                    $this->entityManager->persist($network);
                }

                $network->setLastSeenAt($now);

                $recognised = $recognised || ($deviceKnown && $networkKnown);
            }

            if (null !== $token) {
                $token->setBrowser($described['browser']);
                $token->setOperatingSystem($described['operatingSystem']);
                $token->setLastSeenAt($now);
            } elseif (null !== $firewall) {
                $this->evictToFit(
                    $this->tokens,
                    $userIdentifier,
                    $firewallName,
                );

                $presented = $this->generateToken();

                $token = new KnownDeviceToken();
                $token->setUserIdentifier($userIdentifier);
                $token->setFirewallName($firewallName);
                $token->setTokenHash($this->hashToken($presented));
                $token->setBrowser($described['browser']);
                $token->setOperatingSystem($described['operatingSystem']);
                $token->setFirstSeenAt($now);
                $token->setLastSeenAt($now);

                $this->entityManager->persist($token);
            }

            // Re-issued even when it matched, so the year the browser holds it counts from the last sign-in rather
            // than the first.
            if (
                null !== $firewall
                && is_string($presented)
            ) {
                $this->issueCookie(
                    $request,
                    $firewall,
                    $presented,
                    $now,
                );
            }

            $this->entityManager->flush();

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
     * Note that the facts this account is already recognised on are still being seen.
     *
     * {@see self::recognise()} only runs when somebody signs in with their password, so on its own
     * {@see self::RETENTION} measures the time since the last sign-in rather than the time since the device was last
     * used. Somebody who signs in once and then stays signed in for months would be told about a new device the first
     * time they are made to sign in again, which is the machine they never stopped using.
     *
     * Nothing is created and nothing is revived here. A fact that is not on file, or one that has already lapsed, is
     * one the member should hear about the next time it signs in, and quietly marking either as current would take
     * that notice away.
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

            if (null !== $described['network']) {
                $facts[] = $this->networks->findOneByFingerprint(
                    $userIdentifier,
                    $firewallName,
                    $described['network'],
                );
            }

            $presented = Firewall::tryFrom($firewallName)?->deviceCookieName();
            $presented = null !== $presented
                ? $request->cookies->get($presented)
                : null;

            if (
                is_string($presented)
                && '' !== $presented
            ) {
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
            // This sits on the request path of anybody who is signed in. A fact that goes un-refreshed costs a
            // notice somebody did not need, where an exception let out of here would end their session.
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
     * Forget everything recognition rests on for an account, so that the next sign-in from anywhere is announced.
     *
     * Called when the way into an account changes: a new password, a second factor turned on or off, fresh backup
     * codes, or every other session being signed out. Whether the member is securing an account they think has been
     * reached or an intruder got there first, nothing should stay trusted across it. The cookies out in the world are
     * left where they are; with their rows gone they answer for nothing.
     */
    public function forget(
        string $userIdentifier,
        string $firewallName,
    ): void {
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
     * Make room for one more fact by dropping the ones gone longest unseen.
     *
     * @param KnownFactRepository<covariant KnownFact> $repository
     */
    private function evictToFit(
        KnownFactRepository $repository,
        string $userIdentifier,
        string $firewallName,
    ): void {
        $surplus = $repository->countForUserOnFirewall(
            $userIdentifier,
            $firewallName,
        ) - self::LIMIT + 1;

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

    /**
     * Left on the request for {@see \App\EventListener\User\KnownDeviceCookieListener} to attach, because nothing
     * here holds the response: recognition runs inside the remember-me handler, which reaches its own cookie onto the
     * response the same way.
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
     * Only ever the hash is stored, so that reading the table does not yield cookies that would quiet the notices on
     * somebody's account. Keyed on the application secret, as the fingerprints are.
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

    private function generateToken(): string
    {
        return rtrim(
            strtr(
                base64_encode(random_bytes(32)),
                '+/',
                '-_',
            ),
            '=',
        );
    }
}
