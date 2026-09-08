<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\User;

use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\KnownDevice;
use App\Repository\User\SecurityLogRepository;
use App\Service\User\SecurityEventLogger;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

final class SecurityEventLoggerTest extends DatabaseTestCase
{
    private const string USER = '8000';

    private const string FIREWALL = 'main';

    private const string ADDRESS = '192.0.2.10';

    private const string FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:143.0) Gecko/20100101 Firefox/143.0';

    public function testAnEventIsRecordedWithWhereItCameFrom(): void
    {
        $this->logger()->record(
            SecurityEventType::SessionEndedDeviceChanged,
            self::USER,
            self::FIREWALL,
            ['series' => 'abc'],
            $this->request(),
        );

        $entries = $this->repository()->findAllByUser(self::USER);

        self::assertCount(
            1,
            $entries,
        );
        self::assertSame(
            SecurityEventType::SessionEndedDeviceChanged,
            $entries[0]->getEvent(),
        );
        self::assertSame(
            self::ADDRESS,
            $entries[0]->getIpAddress(),
        );
        self::assertSame(
            'Firefox 143',
            $entries[0]->getBrowser(),
        );
        self::assertSame(
            ['series' => 'abc'],
            $entries[0]->getDetail(),
        );
        self::assertNotNull($entries[0]->getRequestId());
    }

    /**
     * Every arrival from another site resumes a session, because the session cookie is `strict` and does not come
     * with one. Those go to the log file and no further, or they would be most of the table inside a week.
     */
    public function testAResumedSessionIsNotGivenARow(): void
    {
        $this->logger()->record(
            SecurityEventType::SessionResumed,
            self::USER,
            self::FIREWALL,
            [],
            $this->request(),
        );

        self::assertSame(
            [],
            $this->repository()->findAllByUser(self::USER),
        );
    }

    /**
     * The reason the row is written around the ORM. Recording happens halfway through somebody else's work, and a
     * `flush()` here would write out whatever else that work had part-built.
     */
    public function testRecordingDoesNotWriteOutTheCallersPendingWork(): void
    {
        $device = new KnownDevice();
        $device->setUserIdentifier(self::USER);
        $device->setFirewallName(self::FIREWALL);
        $device->setFingerprint('unflushed-fingerprint');
        $device->setFirstSeenAt(new DateTimeImmutable());
        $device->setLastSeenAt(new DateTimeImmutable());

        $this->entityManager->persist($device);

        $this->logger()->record(
            SecurityEventType::SignInSucceeded,
            self::USER,
            self::FIREWALL,
            [],
            $this->request(),
        );

        // Asked of the connection rather than through the ORM, so the unit of work has no chance to write itself out
        // on the way to answering.
        $written = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM KnownDevice WHERE fingerprint = ?',
            ['unflushed-fingerprint'],
        );

        self::assertSame(
            0,
            (int) $written,
        );

        // The event itself did land, which is the other half of the claim.
        self::assertCount(
            1,
            $this->repository()->findAllByUser(self::USER),
        );
    }

    /**
     * An administrator acting on somebody else's account is named as the actor; the row still belongs to the member
     * it was done to.
     */
    public function testAnEventWithoutAnActorNamesNobody(): void
    {
        $this->logger()->record(
            SecurityEventType::SessionTerminated,
            self::USER,
            self::FIREWALL,
            [],
            $this->request(),
        );

        $entries = $this->repository()->findAllByUser(self::USER);

        self::assertNull($entries[0]->getActorIdentifier());
    }

    private function logger(): SecurityEventLogger
    {
        $logger = self::getContainer()->get(SecurityEventLogger::class);
        self::assertInstanceOf(
            SecurityEventLogger::class,
            $logger,
        );

        return $logger;
    }

    private function repository(): SecurityLogRepository
    {
        $repository = self::getContainer()->get(SecurityLogRepository::class);
        self::assertInstanceOf(
            SecurityLogRepository::class,
            $repository,
        );

        return $repository;
    }

    private function request(): Request
    {
        $request = new Request();
        $request->server->set(
            'REMOTE_ADDR',
            self::ADDRESS,
        );
        $request->headers->set(
            'User-Agent',
            self::FIREFOX,
        );

        return $request;
    }
}
