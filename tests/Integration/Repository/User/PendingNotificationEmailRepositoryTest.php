<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\User;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Notification;
use App\Entity\User\PendingNotificationEmail;
use App\Entity\User\User;
use App\Repository\User\PendingNotificationEmailRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;

use function array_map;

final class PendingNotificationEmailRepositoryTest extends DatabaseTestCase
{
    private PendingNotificationEmailRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = self::getContainer()->get(PendingNotificationEmailRepository::class);
    }

    public function testFindUsersWithPendingReturnsEachMemberOnce(): void
    {
        $user = $this->user(8030);
        $other = $this->user(8031);

        $this->queue(
            $user,
            $this->notification('-1 hour'),
        );
        $this->queue(
            $user,
            $this->notification('-2 hours'),
        );
        $this->queue(
            $other,
            $this->notification('-3 hours'),
        );

        $lidnrs = array_map(
            static fn (User $pendingUser): int => $pendingUser->lidnr,
            $this->repository->findUsersWithPending(),
        );

        self::assertEqualsCanonicalizing(
            [
                8030,
                8031,
            ],
            $lidnrs,
        );
        self::assertCount(
            2,
            $lidnrs,
        );
    }

    public function testFindForUserOrdersByNotificationCreation(): void
    {
        $user = $this->user(8030);
        $older = $this->notification('-2 days');
        $newer = $this->notification('-1 day');

        // Queued in the opposite order to how they were published.
        $this->queue(
            $user,
            $newer,
        );
        $this->queue(
            $user,
            $older,
        );

        $pending = $this->repository->findForUser($user);

        self::assertCount(
            2,
            $pending,
        );
        self::assertSame(
            $older->id,
            $pending[0]->notification->id,
        );
        self::assertSame(
            $newer->id,
            $pending[1]->notification->id,
        );
    }

    public function testDeleteForUserOnlyDrainsThatMembersQueue(): void
    {
        $user = $this->user(8030);
        $other = $this->user(8031);
        $this->queue(
            $user,
            $this->notification('-1 day'),
        );
        $this->queue(
            $other,
            $this->notification('-1 day'),
        );

        $this->repository->deleteForUser($user);

        self::assertSame(
            [],
            $this->repository->findForUser($user),
        );
        self::assertCount(
            1,
            $this->repository->findForUser($other),
        );
    }

    private function queue(
        User $user,
        Notification $notification,
    ): PendingNotificationEmail {
        $pending = new PendingNotificationEmail(
            $user,
            $notification,
        );

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        return $pending;
    }

    private function notification(string $createdAt): Notification
    {
        $notification = new Notification();
        $notification->type = NotificationType::AlbumPublished;
        $notification->subjectId = $this->nextSubjectId();
        $notification->createdAt = new DateTimeImmutable($createdAt);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    private function nextSubjectId(): int
    {
        static $subjectId = 900000;

        return (int) ++$subjectId;
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
