<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\User;

use App\Entity\Database\User\ApiPrincipal;
use App\Entity\User\DataExportRequest;
use App\Entity\User\ExternalApp;
use App\Entity\User\KnownDevice;
use App\Entity\User\KnownDeviceToken;
use App\Entity\User\KnownNetwork;
use App\Entity\User\User;
use App\Repository\User\ApiPrincipalRepository;
use App\Repository\User\DataExportRequestRepository;
use App\Repository\User\ExternalAppRepository;
use App\Repository\User\KnownDeviceRepository;
use App\Repository\User\KnownDeviceTokenRepository;
use App\Repository\User\KnownNetworkRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;

final class KnownFactRepositoriesTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
    }

    public function testApiPrincipalTokensAreMatchedByTheirHash(): void
    {
        $repository = self::getContainer()->get(ApiPrincipalRepository::class);

        $principal = $repository->findByToken('smoketest-token');

        self::assertNotNull($principal);
        self::assertNull($repository->findByToken('not-a-token'));
    }

    public function testStampUsageMarksAPrincipalUsedAndIsIdempotentForToday(): void
    {
        $repository = self::getContainer()->get(ApiPrincipalRepository::class);
        $principal = $repository->findByToken('limited-token');
        self::assertNotNull($principal);

        self::assertNull($principal->lastUsedAt);
        $repository->stampUsage($principal);
        self::assertSame(
            new DateTimeImmutable('today')->format('Y-m-d'),
            $principal->lastUsedAt?->format('Y-m-d'),
        );

        // A second call on the same day writes nothing and leaves the value alone.
        $repository->stampUsage($principal);
        self::assertSame(
            new DateTimeImmutable('today')->format('Y-m-d'),
            $principal->lastUsedAt?->format('Y-m-d'),
        );

        // A principal without an identity has nothing to stamp.
        $unsaved = new ApiPrincipal();
        $repository->stampUsage($unsaved);
        self::assertNull($unsaved->lastUsedAt);
    }

    public function testPersistAndRemoveAPrincipal(): void
    {
        $repository = self::getContainer()->get(ApiPrincipalRepository::class);
        $principal = $repository->findByToken('readonly-token');
        self::assertNotNull($principal);

        $principal->description = 'A new description';
        $repository->persist($principal);

        $this->ledger->clear();
        $reloaded = $repository->findByToken('readonly-token');
        self::assertNotNull($reloaded);
        self::assertSame(
            'A new description',
            $reloaded->description,
        );

        $repository->remove($reloaded);
        self::assertNull($repository->findByToken('readonly-token'));
    }

    public function testExternalAppIsFoundByItsApplicationId(): void
    {
        $repository = self::getContainer()->get(ExternalAppRepository::class);

        $app = $repository->findByAppId('sudosos');

        self::assertInstanceOf(
            ExternalApp::class,
            $app,
        );
        self::assertSame(
            'sudosos',
            $app->appId,
        );
        self::assertNull($repository->findByAppId('no-such-app'));
    }

    public function testTheLatestDataExportRequestIsTheMostRecentOne(): void
    {
        $repository = self::getContainer()->get(DataExportRequestRepository::class);
        $user = $this->user(8030);

        $older = new DataExportRequest();
        $older->user = $user;
        $older->requestedAt = new DateTimeImmutable('-2 days');
        $this->entityManager->persist($older);

        $newer = new DataExportRequest();
        $newer->user = $user;
        $newer->requestedAt = new DateTimeImmutable('-1 day');
        $this->entityManager->persist($newer);
        $this->entityManager->flush();

        $latest = $repository->findLatestForUser($user);

        self::assertNotNull($latest);
        self::assertSame(
            $newer->id,
            $latest->id,
        );

        self::assertNull($repository->findLatestForUser($this->user(8031)));
    }

    public function testKnownDeviceLookupsAndTheSharedFactOperations(): void
    {
        $devices = self::getContainer()->get(KnownDeviceRepository::class);

        $older = $this->device('-2 days');
        $newer = $this->device('-1 hour');

        self::assertSame(
            $older,
            $devices->findOneByFingerprint(
                'member:1',
                'main',
                'older',
            ),
        );
        self::assertNull($devices->findOneByFingerprint(
            'member:1',
            'company',
            'older',
        ));

        $all = $devices->findAllByUser('member:1');
        self::assertCount(
            2,
            $all,
        );
        self::assertSame(
            $newer,
            $all[0],
        );
        self::assertSame(
            2,
            $devices->countForUserOnFirewall(
                'member:1',
                'main',
            ),
        );

        $leastRecent = $devices->findLeastRecentlySeen(
            'member:1',
            'main',
            1,
        );
        self::assertCount(
            1,
            $leastRecent,
        );
        self::assertSame(
            $older,
            $leastRecent[0],
        );

        self::assertSame(
            1,
            $devices->deleteSeenBefore(new DateTimeImmutable('-1 day')),
        );
        self::assertCount(
            1,
            $devices->findAllByUser('member:1'),
        );

        self::assertSame(
            1,
            $devices->deleteAllForUserOnFirewall(
                'member:1',
                'main',
            ),
        );
        self::assertCount(
            0,
            $devices->findAllByUser('member:1'),
        );
    }

    public function testKnownNetworkLookup(): void
    {
        $networks = self::getContainer()->get(KnownNetworkRepository::class);

        $network = new KnownNetwork();
        $network->userIdentifier = 'member:2';
        $network->firewallName = 'main';
        $network->fingerprint = 'campus';
        $this->factDates($network);
        $this->entityManager->persist($network);
        $this->entityManager->flush();

        self::assertSame(
            $network,
            $networks->findOneByFingerprint(
                'member:2',
                'main',
                'campus',
            ),
        );
        self::assertNull($networks->findOneByFingerprint(
            'member:2',
            'main',
            'home',
        ));
    }

    public function testKnownDeviceTokenLookupIsScopedToTheAccount(): void
    {
        $tokens = self::getContainer()->get(KnownDeviceTokenRepository::class);

        $token = new KnownDeviceToken();
        $token->userIdentifier = 'member:3';
        $token->firewallName = 'main';
        $token->tokenHash = 'hash';
        $this->factDates($token);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        self::assertSame(
            $token,
            $tokens->findOneByTokenHash(
                'member:3',
                'main',
                'hash',
            ),
        );
        self::assertNull($tokens->findOneByTokenHash(
            'member:4',
            'main',
            'hash',
        ));
    }

    private function device(string $lastSeen): KnownDevice
    {
        static $counter = 0;
        $counter++;

        $device = new KnownDevice();
        $device->userIdentifier = 'member:1';
        $device->firewallName = 'main';
        $device->fingerprint = 1 === $counter
            ? 'older'
            : 'newer';
        $device->firstSeenAt = new DateTimeImmutable('-1 month');
        $device->lastSeenAt = new DateTimeImmutable($lastSeen);

        $this->entityManager->persist($device);
        $this->entityManager->flush();

        return $device;
    }

    private function factDates(KnownDevice|KnownNetwork|KnownDeviceToken $fact): void
    {
        $fact->firstSeenAt = new DateTimeImmutable('-1 month');
        $fact->lastSeenAt = new DateTimeImmutable('-1 hour');
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->find(
            User::class,
            $lidnr,
        );
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
