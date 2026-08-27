<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\KnownDevice;
use App\Repository\User\KnownDeviceRepository;
use App\Security\User\DeviceFingerprint;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
     * How long a device stays recognised without being used. Refreshed on every sign-in from it, so a device somebody
     * actually uses never lapses.
     */
    private const string RETENTION = '-90 days';

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
            $now = new DateTimeImmutable();

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
                $recognised = $device->getLastSeenAt() > new DateTimeImmutable(self::RETENTION);
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
