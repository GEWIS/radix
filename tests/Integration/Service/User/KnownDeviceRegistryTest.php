<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\User;

use App\Entity\User\KnownDevice;
use App\Entity\User\KnownDeviceToken;
use App\Entity\User\KnownNetwork;
use App\Repository\User\KnownDeviceRepository;
use App\Repository\User\KnownDeviceTokenRepository;
use App\Repository\User\KnownNetworkRepository;
use App\Service\User\KnownDeviceRegistry;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class KnownDeviceRegistryTest extends DatabaseTestCase
{
    private const string USER = '8000';

    private const string FIREWALL = 'main';

    private const string CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const string FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0';

    /** Reserved addresses on two different networks, in no IP database and never routed. */
    private const string HOME = '192.0.2.10';

    private const string CAMPUS = '198.51.100.10';

    /**
     * Twenty browsers that differ in nothing but the languages they ask for, which is enough: the languages are part
     * of the device key. Quality values would not be, {@see Request::getLanguages()} strips them.
     */
    private const array LANGUAGES = [
        'aa',
        'ab',
        'af',
        'am',
        'ar',
        'az',
        'be',
        'bg',
        'bn',
        'bs',
        'ca',
        'cs',
        'cy',
        'da',
        'de',
        'el',
        'eo',
        'es',
        'et',
        'eu',
    ];

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

    public function testAKnownDeviceOnANewNetworkIsAnnounced(): void
    {
        $registry = $this->registry();

        $this->recognise(
            $registry,
            $this->request(
                self::CHROME,
                self::HOME,
            ),
        );

        self::assertFalse($this->recognise(
            $registry,
            $this->request(
                self::CHROME,
                self::CAMPUS,
            ),
        ));
    }

    /**
     * The reason device and network are learned apart. The laptop was only ever seen at home and the phone only ever
     * on campus, yet the laptop's first sign-in on campus raises no notice: the device is known, and the network was
     * made known by the phone. One key over both would announce every such pairing for the rest of a membership.
     */
    public function testAKnownDeviceIsRecognisedOnANetworkAnotherDeviceMadeKnown(): void
    {
        $registry = $this->registry();

        $this->recognise(
            $registry,
            $this->request(
                self::CHROME,
                self::HOME,
            ),
        );
        $this->recognise(
            $registry,
            $this->request(
                self::FIREFOX,
                self::CAMPUS,
            ),
        );

        self::assertTrue($this->recognise(
            $registry,
            $this->request(
                self::CHROME,
                self::CAMPUS,
            ),
        ));
    }

    /**
     * The cookie recognises the browser itself, wherever it goes: a member on a network never seen before is not
     * written to as long as the browser can present what it was handed at an earlier sign-in.
     */
    public function testAPresentedCookieIsRecognisedOnANetworkNeverSeenBefore(): void
    {
        $registry = $this->registry();
        $first = $this->request(
            self::CHROME,
            self::HOME,
        );

        $this->recognise(
            $registry,
            $first,
        );

        self::assertTrue($this->recognise(
            $registry,
            $this->request(
                self::CHROME,
                '203.0.113.1',
                $this->issuedCookieValue($first),
            ),
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
     * On a new password, a second factor turned off, or every other session being signed out, nothing stays trusted,
     * the cookies out in the world included.
     */
    public function testForgettingLeavesNothingRecognised(): void
    {
        $registry = $this->registry();
        $request = $this->request(self::CHROME);

        $this->recognise(
            $registry,
            $request,
        );
        $cookie = $this->issuedCookieValue($request);

        $registry->forget(
            self::USER,
            self::FIREWALL,
        );
        $this->entityManager->clear();

        self::assertCount(
            0,
            $this->devices(),
        );
        self::assertCount(
            0,
            $this->networks(),
        );
        self::assertCount(
            0,
            $this->tokens(),
        );
        self::assertFalse($this->recognise(
            $registry,
            $this->request(
                self::CHROME,
                self::HOME,
                $cookie,
            ),
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
                // A different set of languages each time: the languages are part of the device key, where the
                // network deliberately is not.
                $this->request(
                    self::CHROME,
                    languages: self::LANGUAGES[$i],
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
            $this->request(self::FIREFOX),
        );

        self::assertCount(
            20,
            $this->devices(),
        );
        self::assertNull($this->deviceRepository()->findOneByFingerprint(
            self::USER,
            self::FIREWALL,
            $fingerprint,
        ));
    }

    /**
     * Every sign-in without a cookie mints one, so the browsers that never bring theirs back (a member working in
     * private windows) would otherwise pile up rows forever. They sink to the bottom of the least-recently-seen order
     * and make way first.
     */
    public function testTheOldestCookieMakesWayOnceTheCapIsReached(): void
    {
        $registry = $this->registry();

        for ($i = 0; $i < 21; $i++) {
            $registry->recognise(
                self::USER,
                self::FIREWALL,
                $this->request(self::CHROME),
            );

            // Each sign-in a day apart, so the least-recently-seen order is well defined.
            foreach ($this->tokens() as $token) {
                $token->setLastSeenAt($token->getLastSeenAt()->modify('-1 day'));
            }

            $this->entityManager->flush();
        }

        self::assertCount(
            20,
            $this->tokens(),
        );
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

    /**
     * The value {@see KnownDeviceRegistry::recognise()} left to be set as the device cookie, as the browser would
     * carry it to the next sign-in.
     */
    private function issuedCookieValue(Request $request): string
    {
        $cookie = $request->attributes->get(KnownDeviceRegistry::COOKIE_ATTRIBUTE);
        self::assertInstanceOf(
            Cookie::class,
            $cookie,
        );

        $value = $cookie->getValue();
        self::assertNotNull($value);

        return $value;
    }

    private function request(
        string $userAgent,
        string $address = self::HOME,
        ?string $cookie = null,
        string $languages = 'nl-NL,nl;q=0.9,en;q=0.8',
    ): Request {
        $request = new Request();
        $request->headers->set(
            'User-Agent',
            $userAgent,
        );
        $request->headers->set(
            'Accept-Language',
            $languages,
        );
        $request->server->set(
            'REMOTE_ADDR',
            $address,
        );

        if (null !== $cookie) {
            $request->cookies->set(
                'GWS_USER_DEVICE',
                $cookie,
            );
        }

        return $request;
    }

    /** @return KnownDevice[] */
    private function devices(): array
    {
        return $this->deviceRepository()->findAllByUser(self::USER);
    }

    /** @return KnownNetwork[] */
    private function networks(): array
    {
        $repository = self::getContainer()->get(KnownNetworkRepository::class);
        self::assertInstanceOf(
            KnownNetworkRepository::class,
            $repository,
        );

        return $repository->findAllByUser(self::USER);
    }

    /** @return KnownDeviceToken[] */
    private function tokens(): array
    {
        $repository = self::getContainer()->get(KnownDeviceTokenRepository::class);
        self::assertInstanceOf(
            KnownDeviceTokenRepository::class,
            $repository,
        );

        return $repository->findAllByUser(self::USER);
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

    private function deviceRepository(): KnownDeviceRepository
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
