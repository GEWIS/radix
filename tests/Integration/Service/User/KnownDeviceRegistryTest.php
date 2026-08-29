<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\User;

use App\Entity\User\KnownDevice;
use App\Repository\User\KnownDeviceRepository;
use App\Service\User\KnownDeviceRegistry;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

final class KnownDeviceRegistryTest extends DatabaseTestCase
{
    private const string USER = '8000';

    private const string FIREWALL = 'main';

    private const string CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const string FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0';

    /**
     * The first sign-in from a device is announced and every one after it is not.
     */
    public function testADeviceIsAnnouncedOnceAndThenRecognised(): void
    {
        $registry = $this->registry();
        $request = $this->request(self::CHROME);

        self::assertFalse($this->recognise(
            $registry,
            $request,
        ));
        self::assertTrue($this->recognise(
            $registry,
            $request,
        ));
    }

    public function testAnotherDeviceIsAnnouncedOnItsOwnAccount(): void
    {
        $registry = $this->registry();

        self::assertFalse($this->recognise(
            $registry,
            $this->request(self::CHROME),
        ));
        self::assertFalse($this->recognise(
            $registry,
            $this->request(self::FIREFOX),
        ));
    }

    /**
     * Recognition is scoped per firewall, as sessions are: the two account spaces are unrelated.
     */
    public function testRecognitionDoesNotCarryAcrossFirewalls(): void
    {
        $registry = $this->registry();
        $request = $this->request(self::CHROME);

        self::assertFalse($registry->recognise(
            self::USER,
            'main',
            $request,
        ));
        self::assertFalse($registry->recognise(
            self::USER,
            'company',
            $request,
        ));
    }

    /**
     * A device nobody has signed in from for months is announced again, rather than waiting on the pruning job.
     */
    public function testADeviceGoneStaleIsAnnouncedAgain(): void
    {
        $registry = $this->registry();
        $request = $this->request(self::CHROME);

        self::assertFalse($this->recognise(
            $registry,
            $request,
        ));

        $device = $this->onlyDevice();
        $device->setLastSeenAt(new DateTimeImmutable('-121 days'));
        $this->entityManager->flush();

        self::assertFalse($this->recognise(
            $registry,
            $request,
        ));

        // Refreshed rather than duplicated, which the unique constraint would refuse anyway.
        self::assertCount(
            1,
            $this->devices(),
        );
        self::assertTrue($this->recognise(
            $registry,
            $request,
        ));
    }

    /**
     * What a member is shown follows the browser, even though the version is no part of the key.
     */
    public function testTheDisplayedVersionFollowsTheBrowserWhileTheDeviceDoesNot(): void
    {
        $registry = $this->registry();

        $this->recognise(
            $registry,
            $this->request(self::CHROME),
        );
        self::assertSame(
            'Chrome 140',
            $this->onlyDevice()->getBrowser(),
        );

        $updated = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/141.0.0.0 Safari/537.36';

        self::assertTrue($this->recognise(
            $registry,
            $this->request($updated),
        ));
        self::assertSame(
            'Chrome 141',
            $this->onlyDevice()->getBrowser(),
        );
    }

    /**
     * On a new password, a second factor turned off, or every other session being signed out, nothing stays trusted.
     */
    public function testForgettingLeavesNothingRecognised(): void
    {
        $registry = $this->registry();
        $request = $this->request(self::CHROME);

        $this->recognise(
            $registry,
            $request,
        );
        $registry->forget(
            self::USER,
            self::FIREWALL,
        );
        $this->entityManager->clear();

        self::assertCount(
            0,
            $this->devices(),
        );
        self::assertFalse($this->recognise(
            $registry,
            $request,
        ));
    }

    /**
     * The cap stops a stolen password filling the table with fingerprints that suppress everything after them. The
     * device gone longest unused makes way.
     */
    public function testTheOldestDeviceMakesWayOnceTheCapIsReached(): void
    {
        $registry = $this->registry();

        for ($i = 0; $i < 20; $i++) {
            $registry->recognise(
                self::USER,
                self::FIREWALL,
                // A different network each time: the same browser on twenty addresses of one /24 is deliberately one
                // device, which is what the fingerprint tests cover.
                $this->request(
                    self::CHROME,
                    '192.0.' . $i . '.10',
                ),
            );
        }

        self::assertCount(
            20,
            $this->devices(),
        );

        $oldest = $this->devices()[0];
        $oldest->setLastSeenAt(new DateTimeImmutable('-30 days'));
        $this->entityManager->flush();
        $fingerprint = $oldest->getFingerprint();

        $registry->recognise(
            self::USER,
            self::FIREWALL,
            $this->request(
                self::FIREFOX,
                '203.0.113.1',
            ),
        );

        self::assertCount(
            20,
            $this->devices(),
        );
        self::assertNull($this->repository()->findOneByFingerprint(
            self::USER,
            self::FIREWALL,
            $fingerprint,
        ));
    }

    private function recognise(
        KnownDeviceRegistry $registry,
        Request $request,
    ): bool {
        return $registry->recognise(
            self::USER,
            self::FIREWALL,
            $request,
        );
    }

    private function request(
        string $userAgent,
        string $address = '192.0.2.10',
    ): Request {
        $request = new Request();
        $request->headers->set(
            'User-Agent',
            $userAgent,
        );
        $request->server->set(
            'REMOTE_ADDR',
            $address,
        );

        return $request;
    }

    /** @return KnownDevice[] */
    private function devices(): array
    {
        return $this->repository()->findAllByUser(self::USER);
    }

    private function onlyDevice(): KnownDevice
    {
        $devices = $this->devices();
        self::assertCount(
            1,
            $devices,
        );

        return $devices[0];
    }

    private function repository(): KnownDeviceRepository
    {
        $repository = self::getContainer()->get(KnownDeviceRepository::class);
        self::assertInstanceOf(
            KnownDeviceRepository::class,
            $repository,
        );

        return $repository;
    }

    private function registry(): KnownDeviceRegistry
    {
        $registry = self::getContainer()->get(KnownDeviceRegistry::class);
        self::assertInstanceOf(
            KnownDeviceRegistry::class,
            $registry,
        );

        return $registry;
    }
}
