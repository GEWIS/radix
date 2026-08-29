<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\KnownDevice;
use App\Repository\User\KnownDeviceRepository;
use App\Security\User\DeviceFingerprint;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Which devices an account has signed in from before, and therefore which sign-ins are worth writing to somebody
 * about.
 *
 * A member who works in a private window keeps no cookie and leaves no usable session row, so every sign-in used to
 * reach them as a fresh warning, and warnings that arrive daily stop being read at all.
 *
 * Recognition is only ever a reason to withhold a notice. It grants nothing, it is consulted after the sign-in has
 * already succeeded, and every failure here returns false, so a device that goes unrecognised means the member is told.
 */
final readonly class KnownDeviceRegistry
{
    /**
     * How long a device stays recognised without being used. Refreshed on activity from it by {@see self::refresh()},
     * so a device somebody actually uses never lapses.
     *
     * Longer than the longest remember-me cookie in `config/packages/session.yaml` on purpose. Were the two the same
     * length, somebody who signed in once and rode that cookie until it ran out would be told about a new device on
     * the machine they had been on all along, because the sign-in they are forced into lands on the far side of the
     * boundary.
     */
    public const string RETENTION = '-120 days';

    /**
     * How long a device's last-seen is left alone while it is in use. Activity arrives far more often than it is worth
     * writing down, and all this timestamp decides is whether a sign-in months from now is announced.
     */
    private const string REFRESH_THROTTLE = '-1 day';

    /**
     * The most devices kept for one account on one firewall, so that a password in the wrong hands cannot fill the
     * table with fingerprints that suppress everything after them.
     */
    private const int LIMIT = 20;

    public function __construct(
        private KnownDeviceRepository $repository,
        private DeviceFingerprint $fingerprint,
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether this device has signed in to this account before, recording it either way.
     *
     * False means the member should be told: either the device is new, or it was last seen longer ago than
     * {@see self::RETENTION} and is no longer their current machine.
     */
    public function recognise(
        string $userIdentifier,
        string $firewallName,
        Request $request,
    ): bool {
        try {
            $described = $this->fingerprint->describe($request);
            $now = $this->clock->now();

            $device = $this->repository->findOneByFingerprint(
                $userIdentifier,
                $firewallName,
                $described['fingerprint'],
            );

            if (null === $device) {
                $this->evictToFit(
                    $userIdentifier,
                    $firewallName,
                );

                $device = new KnownDevice();
                $device->setUserIdentifier($userIdentifier);
                $device->setFirewallName($firewallName);
                $device->setFingerprint($described['fingerprint']);
                $device->setFirstSeenAt($now);
                $recognised = false;

                $this->entityManager->persist($device);
            } else {
                $recognised = $device->getLastSeenAt() > $now->modify(self::RETENTION);
            }

            // The versions are what a member is shown, so they follow the browser even though they are no part of
            // the key.
            $device->setBrowser($described['browser']);
            $device->setOperatingSystem($described['operatingSystem']);
            $device->setLastSeenAt($now);

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
     * Note that a device this account is already recognised on is still being used.
     *
     * {@see self::recognise()} only runs when somebody signs in with their password, so on its own
     * {@see self::RETENTION} measures the time since the last sign-in rather than the time since the device was last
     * used. Somebody who signs in once and then stays signed in for months would be told about a new device the first
     * time they are made to sign in again, which is the machine they never stopped using.
     *
     * Nothing is created and nothing is revived here. A device that is not on file, or one that has already lapsed, is
     * a device the member should hear about the next time it signs in, and quietly marking either as current would
     * take that notice away.
     */
    public function refresh(
        string $userIdentifier,
        string $firewallName,
        Request $request,
    ): void {
        try {
            $device = $this->repository->findOneByFingerprint(
                $userIdentifier,
                $firewallName,
                $this->fingerprint->describe($request)['fingerprint'],
            );

            if (null === $device) {
                return;
            }

            $now = $this->clock->now();
            $lastSeenAt = $device->getLastSeenAt();

            if (
                $lastSeenAt <= $now->modify(self::RETENTION)
                || $lastSeenAt > $now->modify(self::REFRESH_THROTTLE)
            ) {
                return;
            }

            $device->setLastSeenAt($now);

            $this->entityManager->flush();
        } catch (Throwable $e) {
            // This sits on the request path of anybody who is signed in. A device that goes un-refreshed costs a
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
     * Forget every device on an account, so that the next sign-in from any of them is announced.
     *
     * Called when the way into an account changes: a new password, a second factor turned on or off, fresh backup
     * codes, or every other session being signed out. Whether the member is securing an account they think has been
     * reached or an intruder got there first, nothing should stay trusted across it.
     */
    public function forget(
        string $userIdentifier,
        string $firewallName,
    ): void {
        try {
            $this->repository->deleteAllForUserOnFirewall(
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
     * Make room for one more device by dropping the ones gone longest unused.
     */
    private function evictToFit(
        string $userIdentifier,
        string $firewallName,
    ): void {
        $surplus = $this->repository->countForUserOnFirewall(
            $userIdentifier,
            $firewallName,
        ) - self::LIMIT + 1;

        if ($surplus <= 0) {
            return;
        }

        foreach (
            $this->repository->findLeastRecentlySeen(
                $userIdentifier,
                $firewallName,
                $surplus,
            ) as $device
        ) {
            $this->entityManager->remove($device);
        }
    }
}
