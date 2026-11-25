<?php

declare(strict_types=1);

namespace App\Tests\Integration\Twig\Components\Notification;

use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Notification;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Notification\Bell;
use DateTimeImmutable;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function implode;

/**
 * Dismissing a line hides it for that member alone, because a broadcast notification is one row for everybody;
 * marking everything read moves the point the badge counts from.
 */
final class BellActionsTest extends DatabaseTestCase
{
    public function testDismissingALineHidesItForThatMemberAlone(): void
    {
        $this->broadcast();
        $this->entityManager->flush();

        $mine = $this->bellFor(8025);
        $entry = $mine->getEntries()[0];
        $mine->dismiss(implode(
            ',',
            $entry['ids'],
        ));

        self::assertSame(
            [],
            $mine->getEntries(),
        );
        self::assertCount(
            1,
            $this->bellFor(8006)->getEntries(),
            'The notification belongs to everybody; only this member dismissed it.',
        );
    }

    /**
     * A dismissed line is also read: it stops counting towards the badge even though its moment is gone from the list.
     */
    public function testDismissingAlsoCountsAsRead(): void
    {
        $this->broadcast();
        $this->entityManager->flush();

        $bell = $this->bellFor(8025);
        self::assertSame(
            1,
            $bell->getUnreadCount(),
        );

        $bell->dismiss(implode(
            ',',
            $bell->getEntries()[0]['ids'],
        ));

        self::assertSame(
            0,
            $bell->getUnreadCount(),
        );
    }

    public function testDismissAllEmptiesTheCentreAndTheBadge(): void
    {
        $this->signIn('Chrome 124');
        $this->signIn('Firefox 153');
        $this->broadcast();
        $this->entityManager->flush();

        $bell = $this->bellFor(8025);
        self::assertSame(
            3,
            $bell->getBadgeCount(),
        );

        $bell->dismissAll();

        self::assertSame(
            [],
            $bell->getEntries(),
        );
        self::assertSame(
            0,
            $bell->getBadgeCount(),
        );
        self::assertCount(
            1,
            $this->bellFor(8006)->getEntries(),
            'The broadcast is everybody\'s; dismissing it is this member\'s own doing.',
        );
    }

    public function testAnIdThatIsNotOnShowReachesNothing(): void
    {
        $this->broadcast();
        $this->entityManager->flush();

        $bell = $this->bellFor(8025);

        $bell->markRead('999999');
        $bell->dismiss('not-a-number');

        self::assertCount(
            1,
            $bell->getEntries(),
        );
        self::assertSame(
            1,
            $bell->getUnreadCount(),
        );
    }

    public function testMarkingEverythingReadMovesThePointTheBadgeCountsFrom(): void
    {
        $this->broadcast();
        $this->entityManager->flush();
        $bell = $this->bellFor(8025);
        self::assertNull($bell->getReadAt());

        $bell->markAllRead();

        self::assertNotNull($bell->getReadAt());
        self::assertSame(
            0,
            $bell->getUnreadCount(),
        );

        // What arrives after that moment is unread again.
        $this->signIn('Chrome 124');
        $this->entityManager->flush();

        $again = $this->bellFor(8025);
        self::assertSame(
            1,
            $again->getUnreadCount(),
        );
    }

    private function signIn(string $browser): Notification
    {
        $notification = new Notification();
        $notification->type = NotificationType::SignIn;
        $notification->context = ['browser' => $browser];
        $notification->setRecipient(
            $this->member(8025),
            null,
        );
        $notification->createdAt = new DateTimeImmutable();
        $this->entityManager->persist($notification);

        return $notification;
    }

    private function broadcast(): Notification
    {
        $notification = new Notification();
        $notification->type = NotificationType::AlbumPublished;
        $notification->subjectId = 1;
        $notification->createdAt = new DateTimeImmutable();
        $this->entityManager->persist($notification);

        return $notification;
    }

    private function bellFor(int $lidnr): Bell
    {
        $user = $this->member($lidnr);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            ['ROLE_USER'],
        ));

        return self::getContainer()->get(Bell::class);
    }

    private function member(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
