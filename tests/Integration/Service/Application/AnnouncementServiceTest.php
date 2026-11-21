<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Application;

use App\Entity\Application\Announcement;
use App\Entity\Application\ApplicationLocalisedText;
use App\Entity\Application\Enums\AlertTypes;
use App\Repository\Application\AnnouncementRepository;
use App\Service\Application\AnnouncementService;
use App\Service\Application\RealtimeNotifier;
use App\Service\Application\RealtimeTopics;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Broadcasting and storing are one operation: a non-sticky announcement is a one-off that is not stored, so whether a
 * row is written is part of sending.
 */
final class AnnouncementServiceTest extends DatabaseTestCase
{
    public function testAOneOffAnnouncementIsBroadcastButNotStored(): void
    {
        $hub = $this->hub();
        $announcement = $this->announcement();

        $this->service($hub)->send(
            $announcement,
            false,
            new DateTimeImmutable('+1 hour'),
        );

        self::assertNull($announcement->id);
    }

    /**
     * A sticky announcement without an end is not sticky either: there is nothing to take down later.
     */
    public function testAStickyAnnouncementWithoutAnEndIsNotStored(): void
    {
        $hub = $this->hub();
        $announcement = $this->announcement();

        $this->service($hub)->send(
            $announcement,
            true,
            null,
        );

        self::assertNull($announcement->id);
    }

    public function testAStickyAnnouncementIsStoredWithItsEnd(): void
    {
        $hub = $this->hub();
        $announcement = $this->announcement();
        $endsAt = new DateTimeImmutable('+2 hours');

        $this->service($hub)->send(
            $announcement,
            true,
            $endsAt,
        );

        self::assertNotNull($announcement->id);
        self::assertSame(
            $endsAt,
            $announcement->endsAt,
        );

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(Announcement::class)->find($announcement->id);
        self::assertInstanceOf(
            Announcement::class,
            $stored,
        );
        self::assertSame(
            AlertTypes::Warning,
            $stored->level,
        );
    }

    public function testTakingDownAnAnnouncementThatIsThereReportsIt(): void
    {
        $hub = $this->hub();
        $announcement = $this->announcement();
        $announcement->endsAt = new DateTimeImmutable('+1 hour');
        $announcement->createdAt = new DateTimeImmutable();
        $this->entityManager->persist($announcement);
        $this->entityManager->flush();
        $id = (int) $announcement->id;

        self::assertTrue($this->service($hub)->remove($id));

        self::assertNull($this->entityManager->getRepository(Announcement::class)->find($id));
    }

    public function testTakingDownSomethingThatIsNotThereReportsNothingHappened(): void
    {
        self::assertFalse($this->service($this->hub())->remove(999999));
    }

    public function testTheBroadcastGoesToThePublicTopic(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(
                static fn (Update $update): bool => [RealtimeTopics::PUBLIC] === $update->getTopics(),
            ));

        $this->service($hub)->send(
            $this->announcement(),
            false,
            null,
        );
    }

    private function service(HubInterface $hub): AnnouncementService
    {
        return new AnnouncementService(
            $this->entityManager,
            self::getContainer()->get(AnnouncementRepository::class),
            new RealtimeNotifier(
                $hub,
                new RealtimeTopics('secret'),
            ),
        );
    }

    private function hub(): HubInterface
    {
        $hub = self::createStub(HubInterface::class);
        $hub->method('publish')->willReturn('update-id');

        return $hub;
    }

    private function announcement(): Announcement
    {
        $announcement = new Announcement();
        $announcement->level = AlertTypes::Warning;
        $announcement->title = new ApplicationLocalisedText(
            'Maintenance',
            'Onderhoud',
        );
        $announcement->body = new ApplicationLocalisedText(
            'Tonight at 22:00',
            'Vanavond om 22:00',
        );

        return $announcement;
    }
}
