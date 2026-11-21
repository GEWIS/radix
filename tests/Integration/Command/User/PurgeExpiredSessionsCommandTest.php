<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\User;

use App\Entity\User\Enums\DeviceTypes;
use App\Entity\User\KnownDevice;
use App\Entity\User\KnownDeviceToken;
use App\Entity\User\KnownNetwork;
use App\Entity\User\Session;
use App\Repository\User\KnownDeviceRepository;
use App\Repository\User\KnownDeviceTokenRepository;
use App\Repository\User\KnownNetworkRepository;
use App\Repository\User\SessionRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

use function hash;

/**
 * A session ends when it expires, or when it was unused for a month even though the cookie lasts ninety days. A
 * device, network or cookie is forgotten long after recognition stopped using it, and an active session on a
 * device in use survives.
 */
final class PurgeExpiredSessionsCommandTest extends DatabaseTestCase
{
    public function testAnExpiredOrIdleSessionIsSweptAndAMemberInUseIsKept(): void
    {
        $this->session(
            'sweep-expired',
            '-1 hour',
            '-2 days',
        );
        $this->session(
            'sweep-idle',
            '+30 days',
            '-40 days',
        );
        $this->session(
            'sweep-active',
            '+30 days',
            '-1 day',
        );

        $result = static::runCommand('app:user:purge-expired-sessions');
        $this->assertCommandIsSuccessful($result);

        $this->entityManager->clear();
        self::assertNull(
            $this->sessions()->findOneBySeries('sweep-expired'),
            'A session past its expiry can never be reached again.',
        );
        self::assertNull(
            $this->sessions()->findOneBySeries('sweep-idle'),
            'A session nobody has used in a month is signed out rather than kept for the rest of the ninety days.',
        );
        self::assertNotNull(
            $this->sessions()->findOneBySeries('sweep-active'),
        );
        self::assertMatchesRegularExpression(
            '/Purged \d+ expired and \d+ idle session/',
            $result->getDisplay(),
        );
    }

    public function testRecognitionFactsThatLapsedAreForgotten(): void
    {
        $this->device(
            'sweep-device-old',
            '-130 days',
        );
        $this->device(
            'sweep-device-recent',
            '-1 day',
        );
        $this->network(
            'sweep-network-old',
            '-130 days',
        );
        $this->network(
            'sweep-network-recent',
            '-1 day',
        );
        $this->token(
            'sweep-token-old',
            '-130 days',
        );
        $this->token(
            'sweep-token-recent',
            '-1 day',
        );

        $this->assertCommandIsSuccessful(static::runCommand('app:user:purge-expired-sessions'));

        $this->entityManager->clear();
        self::assertNull($this->devices()->findOneBy(['fingerprint' => 'sweep-device-old']));
        self::assertNotNull($this->devices()->findOneBy(['fingerprint' => 'sweep-device-recent']));
        self::assertNull($this->networks()->findOneBy(['fingerprint' => 'sweep-network-old']));
        self::assertNotNull($this->networks()->findOneBy(['fingerprint' => 'sweep-network-recent']));
        self::assertNull($this->tokens()->findOneBy(['tokenHash' => 'sweep-token-old']));
        self::assertNotNull($this->tokens()->findOneBy(['tokenHash' => 'sweep-token-recent']));
    }

    /**
     * The cutoff is the registry's retention rather than a second constant, so a fact that recognition still uses is
     * not removed.
     */
    public function testAFactWithinTheRetentionIsKept(): void
    {
        $this->device(
            'sweep-device-inside',
            '-119 days',
        );

        $this->assertCommandIsSuccessful(static::runCommand('app:user:purge-expired-sessions'));

        $this->entityManager->clear();
        self::assertNotNull($this->devices()->findOneBy(['fingerprint' => 'sweep-device-inside']));
    }

    private function session(
        string $series,
        string $expiresAt,
        string $lastUsedAt,
    ): Session {
        $session = new Session();
        $session->series = $series;
        $session->hashedToken = hash(
            'sha256',
            $series,
        );
        $session->signature = 'a-signature';
        $session->signaturePropertiesHash = 'a-properties-hash';
        $session->firewallName = 'main';
        $session->userIdentifier = '8000';
        $session->createdAt = new DateTimeImmutable('-90 days');
        $session->expiresAt = new DateTimeImmutable($expiresAt);
        $session->lastUsedAt = new DateTimeImmutable($lastUsedAt);
        $session->userAgent = 'a browser';
        $session->ipAddress = '127.0.0.1';
        $session->deviceType = DeviceTypes::Pc;
        $session->phpSessionId = 'php-' . $series;

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function device(
        string $fingerprint,
        string $lastSeenAt,
    ): KnownDevice {
        $device = new KnownDevice();
        $device->fingerprint = $fingerprint;
        $this->fact(
            $device,
            $lastSeenAt,
        );
        $this->entityManager->persist($device);
        $this->entityManager->flush();

        return $device;
    }

    private function network(
        string $fingerprint,
        string $lastSeenAt,
    ): KnownNetwork {
        $network = new KnownNetwork();
        $network->fingerprint = $fingerprint;
        $this->fact(
            $network,
            $lastSeenAt,
        );
        $this->entityManager->persist($network);
        $this->entityManager->flush();

        return $network;
    }

    private function token(
        string $tokenHash,
        string $lastSeenAt,
    ): KnownDeviceToken {
        $token = new KnownDeviceToken();
        $token->tokenHash = $tokenHash;
        $this->fact(
            $token,
            $lastSeenAt,
        );
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    private function fact(
        KnownDevice|KnownNetwork|KnownDeviceToken $fact,
        string $lastSeenAt,
    ): void {
        $fact->userIdentifier = '8000';
        $fact->firewallName = 'main';
        $fact->firstSeenAt = new DateTimeImmutable($lastSeenAt);
        $fact->lastSeenAt = new DateTimeImmutable($lastSeenAt);
    }

    private function sessions(): SessionRepository
    {
        return $this->entityManager->getRepository(Session::class);
    }

    private function devices(): KnownDeviceRepository
    {
        return $this->entityManager->getRepository(KnownDevice::class);
    }

    private function networks(): KnownNetworkRepository
    {
        return $this->entityManager->getRepository(KnownNetwork::class);
    }

    private function tokens(): KnownDeviceTokenRepository
    {
        return $this->entityManager->getRepository(KnownDeviceToken::class);
    }
}
