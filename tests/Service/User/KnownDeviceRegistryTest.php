<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\User\KnownDevice;
use App\Entity\User\KnownDeviceToken;
use App\Entity\User\KnownFact;
use App\Entity\User\KnownNetwork;
use App\Repository\User\KnownDeviceRepository;
use App\Repository\User\KnownDeviceTokenRepository;
use App\Repository\User\KnownNetworkRepository;
use App\Security\User\DeviceFingerprint;
use App\Security\User\IpNetworkResolver;
use App\Security\User\UserAgentParser;
use App\Service\User\KnownDeviceRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_values;
use function hash_hmac;

/**
 * When a device counts as one this account has been on before, and what keeps it counting as one.
 */
final class KnownDeviceRegistryTest extends TestCase
{
    private const string NOW = '2026-08-28 12:00:00';

    private const string SECRET = 'a secret';

    private const string CHROME_140 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    /**
     * The remember-me cookie on the main firewall lives 90 days, so a member who signs in once and stays signed in
     * comes back for their next sign-in on day 90. Retention has to reach past that or they are told about a new
     * device on the machine they never left.
     */
    public function testADeviceAndNetworkLastSeenWhenTheLongestCookieRunsOutAreStillRecognised(): void
    {
        self::assertTrue($this->registry(
            device: $this->device('-90 days'),
            network: $this->network('-90 days'),
        )->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    public function testADeviceNobodyHasUsedInFourMonthsIsNotRecognised(): void
    {
        self::assertFalse($this->registry(
            device: $this->device('-121 days'),
            network: $this->network('-1 day'),
        )->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    public function testADeviceOnFileDoesNotVouchForANetworkThatIsNot(): void
    {
        self::assertFalse($this->registry(device: $this->device('-1 day'))->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    public function testANetworkOnFileDoesNotVouchForADeviceThatIsNot(): void
    {
        self::assertFalse($this->registry(network: $this->network('-1 day'))->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    public function testANetworkNobodyHasUsedInFourMonthsIsNotRecognised(): void
    {
        self::assertFalse($this->registry(
            device: $this->device('-1 day'),
            network: $this->network('-121 days'),
        )->recognise(
            'somebody',
            'main',
            $this->request(),
        ));
    }

    /**
     * An address that does not parse is a network recognition cannot vouch for, so even a known device on it is
     * announced: the address is what an attacker would vary.
     */
    public function testAKnownDeviceOnAnUnnameableNetworkIsNotRecognised(): void
    {
        self::assertFalse($this->registry(
            device: $this->device('-1 day'),
            network: $this->network('-1 day'),
        )->recognise(
            'somebody',
            'main',
            $this->request(address: 'not-an-address'),
        ));
    }

    /**
     * The cookie is the one exact answer: wherever the browser goes, presenting it is being the same browser.
     */
    public function testAPresentedDeviceCookieIsRecognisedOnItsOwn(): void
    {
        self::assertTrue($this->registry(token: $this->token('-30 days'))->recognise(
            'somebody',
            'main',
            $this->request(cookie: 'the-raw-token'),
        ));
    }

    public function testALapsedDeviceCookieVouchesForNothing(): void
    {
        self::assertFalse($this->registry(token: $this->token('-121 days'))->recognise(
            'somebody',
            'main',
            $this->request(cookie: 'the-raw-token'),
        ));
    }

    /**
     * A first sign-in without a cookie leaves with one, and what the browser holds is never what the table holds.
     */
    public function testASignInWithoutACookieIsHandedOneWhoseHashIsStored(): void
    {
        $persisted = [];
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            },
        );

        $request = $this->request();
        $this->registry(entityManager: $entityManager)->recognise(
            'somebody',
            'main',
            $request,
        );

        $cookie = $request->attributes->get(KnownDeviceRegistry::COOKIE_ATTRIBUTE);
        self::assertInstanceOf(
            Cookie::class,
            $cookie,
        );
        self::assertSame(
            'GWS_USER_DEVICE',
            $cookie->getName(),
        );

        $tokens = array_filter(
            $persisted,
            static fn (object $entity): bool => $entity instanceof KnownDeviceToken,
        );
        self::assertCount(
            1,
            $tokens,
        );

        $value = $cookie->getValue();
        self::assertNotNull($value);
        self::assertSame(
            hash_hmac(
                'sha256',
                $value,
                self::SECRET,
            ),
            array_values($tokens)[0]->getTokenHash(),
        );
    }

    /**
     * Re-issued so the year the browser holds it counts from the last sign-in rather than the first.
     */
    public function testAMatchedCookieIsHandedBackRatherThanReplaced(): void
    {
        $request = $this->request(cookie: 'the-raw-token');
        $this->registry(token: $this->token('-30 days'))->recognise(
            'somebody',
            'main',
            $request,
        );

        $cookie = $request->attributes->get(KnownDeviceRegistry::COOKIE_ATTRIBUTE);
        self::assertInstanceOf(
            Cookie::class,
            $cookie,
        );
        self::assertSame(
            'the-raw-token',
            $cookie->getValue(),
        );
    }

    /**
     * A cookie whose row was never written would name nothing, so nothing may be handed out when the flush fails.
     */
    public function testNoCookieIsHandedOutWhenNothingWasWritten(): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException(new RuntimeException('database gone'));

        $request = $this->request();

        self::assertFalse($this->registry(entityManager: $entityManager)->recognise(
            'somebody',
            'main',
            $request,
        ));
        self::assertNull($request->attributes->get(KnownDeviceRegistry::COOKIE_ATTRIBUTE));
    }

    public function testWorkingInADeviceKeepsItsFactsRecognised(): void
    {
        $device = $this->device('-100 days');
        $network = $this->network('-100 days');
        $token = $this->token('-100 days');

        $this->registry(
            device: $device,
            network: $network,
            token: $token,
        )->refresh(
            'somebody',
            'main',
            $this->request(cookie: 'the-raw-token'),
        );

        $now = new MockClock(self::NOW)->now();
        self::assertEquals(
            $now,
            $device->getLastSeenAt(),
        );
        self::assertEquals(
            $now,
            $network->getLastSeenAt(),
        );
        self::assertEquals(
            $now,
            $token->getLastSeenAt(),
        );
    }

    /**
     * Activity arrives far more often than it is worth writing down.
     */
    public function testADeviceSeenWithinTheDayIsLeftAlone(): void
    {
        $device = $this->device('-2 hours');
        $lastSeenAt = $device->getLastSeenAt();

        $this->registry(device: $device)->refresh(
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
     * A fact that has already lapsed has a notice owing on it, and quietly marking it current would take that away.
     */
    public function testALapsedDeviceIsNotRevivedByUsingIt(): void
    {
        $device = $this->device('-121 days');
        $lastSeenAt = $device->getLastSeenAt();

        $this->registry(device: $device)->refresh(
            'somebody',
            'main',
            $this->request(),
        );

        self::assertSame(
            $lastSeenAt,
            $device->getLastSeenAt(),
        );
    }

    public function testNothingOnFileIsWrittenByRefreshing(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $this->registry(entityManager: $entityManager)->refresh(
            'somebody',
            'main',
            $this->request(cookie: 'the-raw-token'),
        );
    }

    private function registry(
        ?KnownDevice $device = null,
        ?KnownNetwork $network = null,
        ?KnownDeviceToken $token = null,
        ?EntityManagerInterface $entityManager = null,
    ): KnownDeviceRegistry {
        $devices = self::createStub(KnownDeviceRepository::class);
        $devices->method('findOneByFingerprint')->willReturn($device);

        $networks = self::createStub(KnownNetworkRepository::class);
        $networks->method('findOneByFingerprint')->willReturn($network);

        $tokens = self::createStub(KnownDeviceTokenRepository::class);
        $tokens->method('findOneByTokenHash')->willReturn($token);

        return new KnownDeviceRegistry(
            $devices,
            $networks,
            $tokens,
            new DeviceFingerprint(
                new UserAgentParser(),
                new IpNetworkResolver('/nonexistent'),
                self::SECRET,
            ),
            $entityManager ?? self::createStub(EntityManagerInterface::class),
            new MockClock(self::NOW),
            new NullLogger(),
            self::SECRET,
        );
    }

    private function device(string $lastSeen): KnownDevice
    {
        $device = new KnownDevice();
        $device->setFingerprint('a fingerprint');

        return $this->seen(
            $device,
            $lastSeen,
        );
    }

    private function network(string $lastSeen): KnownNetwork
    {
        $network = new KnownNetwork();
        $network->setFingerprint('a fingerprint');

        return $this->seen(
            $network,
            $lastSeen,
        );
    }

    private function token(string $lastSeen): KnownDeviceToken
    {
        $token = new KnownDeviceToken();
        $token->setTokenHash(hash_hmac(
            'sha256',
            'the-raw-token',
            self::SECRET,
        ));

        return $this->seen(
            $token,
            $lastSeen,
        );
    }

    /**
     * @template T of KnownFact
     *
     * @param T $fact
     *
     * @return T
     */
    private function seen(
        object $fact,
        string $lastSeen,
    ): object {
        $fact->setUserIdentifier('somebody');
        $fact->setFirewallName('main');
        $fact->setFirstSeenAt(new MockClock(self::NOW)->now()->modify('-1 year'));
        $fact->setLastSeenAt(new MockClock(self::NOW)->now()->modify($lastSeen));

        return $fact;
    }

    private function request(
        string $address = '192.0.2.10',
        ?string $cookie = null,
    ): Request {
        $request = new Request();
        $request->headers->set(
            'User-Agent',
            self::CHROME_140,
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
}
