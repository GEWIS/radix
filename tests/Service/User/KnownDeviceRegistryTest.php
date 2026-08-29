<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\User\KnownDevice;
use App\Repository\User\KnownDeviceRepository;
use App\Security\User\DeviceFingerprint;
use App\Security\User\UserAgentParser;
use App\Service\User\KnownDeviceRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * When a device counts as one this account has been on before, and what keeps it counting as one.
 */
final class KnownDeviceRegistryTest extends TestCase
{
    private const string NOW = '2026-08-28 12:00:00';

    private const string CHROME_140 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    /**
     * The remember-me cookie on the main firewall lives 90 days, so a member who signs in once and stays signed in
     * comes back for their next sign-in on day 90. Retention has to reach past that or they are told about a new
     * device on the machine they never left.
     */
    public function testADeviceLastSeenWhenTheLongestCookieRunsOutIsStillRecognised(): void
    {
        $device = $this->device('-90 days');

        self::assertTrue($this->registry($device)->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    public function testADeviceNobodyHasUsedInFourMonthsIsNotRecognised(): void
    {
        $device = $this->device('-121 days');

        self::assertFalse($this->registry($device)->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    public function testADeviceNotOnFileIsNotRecognised(): void
    {
        self::assertFalse($this->registry(null)->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    public function testWorkingInADeviceKeepsItRecognised(): void
    {
        $device = $this->device('-100 days');

        $this->registry($device)->refresh(
            'somebody',
            'main',
            $this->request(),
        );

        self::assertEquals(
            new MockClock(self::NOW)->now(),
            $device->getLastSeenAt(),
        );
    }

    /**
     * Activity arrives far more often than it is worth writing down.
     */
    public function testADeviceSeenWithinTheDayIsLeftAlone(): void
    {
        $device = $this->device('-2 hours');
        $lastSeenAt = $device->getLastSeenAt();

        $this->registry($device)->refresh(
            'somebody',
            'main',
            $this->request(),
        );

        self::assertSame(
            $lastSeenAt,
            $device->getLastSeenAt(),
        );
    }

    /**
     * A device that has already lapsed has a notice owing on it, and quietly marking it current would take that away.
     */
    public function testALapsedDeviceIsNotRevivedByUsingIt(): void
    {
        $device = $this->device('-121 days');
        $lastSeenAt = $device->getLastSeenAt();

        $this->registry($device)->refresh(
            'somebody',
            'main',
            $this->request(),
        );

        self::assertSame(
            $lastSeenAt,
            $device->getLastSeenAt(),
        );
    }

    public function testADeviceNotOnFileIsNotWrittenByRefreshing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $this->registry(
            null,
            $entityManager,
        )->refresh(
            'somebody',
            'main',
            $this->request(),
        );
    }

    private function registry(
        ?KnownDevice $device,
        ?EntityManagerInterface $entityManager = null,
    ): KnownDeviceRegistry {
        $repository = self::createStub(KnownDeviceRepository::class);
        $repository->method('findOneByFingerprint')->willReturn($device);

        return new KnownDeviceRegistry(
            $repository,
            new DeviceFingerprint(
                new UserAgentParser(),
                'a secret',
            ),
            $entityManager ?? self::createStub(EntityManagerInterface::class),
            new MockClock(self::NOW),
            new NullLogger(),
        );
    }

    private function device(string $lastSeen): KnownDevice
    {
        $device = new KnownDevice();
        $device->setUserIdentifier('somebody');
        $device->setFirewallName('main');
        $device->setFingerprint('a fingerprint');
        $device->setFirstSeenAt(new MockClock(self::NOW)->now()->modify('-1 year'));
        $device->setLastSeenAt(new MockClock(self::NOW)->now()->modify($lastSeen));

        return $device;
    }

    private function request(): Request
    {
        $request = new Request();
        $request->headers->set(
            'User-Agent',
            self::CHROME_140,
        );
        $request->server->set(
            'REMOTE_ADDR',
            '192.0.2.10',
        );

        return $request;
    }
}
