<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\User;

use App\Entity\User\Enums\DeviceTypes;
use App\Entity\User\Session;
use App\Repository\User\SessionRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;

final class SessionRepositoryTest extends DatabaseTestCase
{
    private const string USER = '8001';

    /**
     * `expiresAt` is fixed when the row is written and never extended, so without this sweep a private window that
     * was closed rather than signed out of sits in the member's device list for the rest of its ninety days.
     */
    public function testAnAbandonedSessionIsSweptLongBeforeItExpires(): void
    {
        $abandoned = $this->session(
            'abandoned',
            lastUsedAt: new DateTimeImmutable('-31 days'),
            expiresAt: new DateTimeImmutable('+59 days'),
        );
        $inUse = $this->session(
            'in-use',
            lastUsedAt: new DateTimeImmutable('-1 day'),
            expiresAt: new DateTimeImmutable('+59 days'),
        );

        $this->entityManager->flush();

        self::assertSame(
            1,
            $this->repository()->deleteIdleSince(new DateTimeImmutable('-30 days')),
        );

        $remaining = $this->repository()->findAllByUserOnFirewall(
            self::USER,
            'main',
        );
        self::assertCount(
            1,
            $remaining,
        );
        self::assertSame(
            $inUse->getSeries(),
            $remaining[0]->getSeries(),
        );
        self::assertNull($this->repository()->findOneBySeries($abandoned->getSeries()));
    }

    /**
     * A device still in use keeps its `lastUsedAt` moving, so the sweep never reaches it however old the row is.
     */
    public function testASessionInDailyUseSurvivesTheSweep(): void
    {
        $this->session(
            'old-but-busy',
            lastUsedAt: new DateTimeImmutable('-2 hours'),
            expiresAt: new DateTimeImmutable('+1 day'),
        );
        $this->entityManager->flush();

        self::assertSame(
            0,
            $this->repository()->deleteIdleSince(new DateTimeImmutable('-30 days')),
        );
    }

    private function session(
        string $series,
        DateTimeImmutable $lastUsedAt,
        DateTimeImmutable $expiresAt,
    ): Session {
        $session = new Session();
        $session->setSeries($series);
        $session->setHashedToken('hashed-' . $series);
        $session->setSignature('signature-' . $series);
        $session->setSignaturePropertiesHash('properties-' . $series);
        $session->setFirewallName('main');
        $session->setUserIdentifier(self::USER);
        $session->setCreatedAt(new DateTimeImmutable('-60 days'));
        $session->setExpiresAt($expiresAt);
        $session->setLastUsedAt($lastUsedAt);
        $session->setUserAgent('a user agent');
        $session->setIpAddress('192.0.2.10');
        $session->setDeviceType(DeviceTypes::Pc);
        $session->setPhpSessionId('php-' . $series);

        $this->entityManager->persist($session);

        return $session;
    }

    private function repository(): SessionRepository
    {
        $repository = self::getContainer()->get(SessionRepository::class);
        self::assertInstanceOf(
            SessionRepository::class,
            $repository,
        );

        return $repository;
    }
}
