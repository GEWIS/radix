<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Application;

use App\Command\Application\RunNotificationDigestsCommand;
use App\Entity\Application\Enums\NotificationEmailFrequency;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\Application\Notification;
use App\Entity\User\NotificationEmailSubscription;
use App\Entity\User\PendingNotificationEmail;
use App\Entity\User\User;
use App\Message\Application\SendNotificationDigestMessage;
use App\Repository\User\NotificationEmailSubscriptionRepository;
use App\Repository\User\PendingNotificationEmailRepository;
use App\Repository\User\UserSettingsRepository;
use App\Service\Application\NotificationSubjectNamerInterface;
use App\Service\Application\NotificationSubjectResolver;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The digest is the only thing that empties the notification queue. The tests cover four decisions per member:
 * what is due, what is dropped because its subject is gone, what waits, and what a member who paused email keeps.
 */
final class RunNotificationDigestsCommandTest extends DatabaseTestCase
{
    /** @var list<SendNotificationDigestMessage> */
    private array $mailed = [];

    private User $member;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mailed = [];
        $member = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $member,
            'The seed is expected to contain member 8030.',
        );
        $this->member = $member;
    }

    public function testADueDigestIsMailedAndItsNotificationsLeaveTheQueue(): void
    {
        $this->queue(501);
        $subscription = $this->subscribe(NotificationEmailFrequency::Immediately);

        $this->runDigests();

        self::assertCount(
            1,
            $this->mailed,
        );
        $message = $this->mailed[0];
        self::assertSame(
            $this->member->member->email,
            $message->getEmail(),
        );
        self::assertSame(
            $this->member->member->getFullName(),
            $message->getName(),
        );
        self::assertCount(
            1,
            $message->getEntries(),
        );
        self::assertSame(
            501,
            $message->getEntries()[0]['subjectId'],
            'The entry keeps the subject so the mail can link to it.',
        );
        self::assertSame(
            NotificationType::AlbumPublished,
            $message->getEntries()[0]['type'],
        );
        self::assertNotSame(
            '',
            $message->getEntries()[0]['text'],
        );

        self::assertSame(
            [],
            $this->pending()->findForUser($this->member),
            'What was mailed must not be mailed again.',
        );
        self::assertNotNull(
            $this->reread($subscription)->lastSentAt,
        );
    }

    /**
     * Pausing email empties the queue rather than keeping it, so a member who returns does not get a month of
     * digests at once.
     */
    public function testAMemberWhoPausedEmailKeepsNothingQueued(): void
    {
        $this->queue(502);
        $this->subscribe(NotificationEmailFrequency::Immediately);

        $settings = $this->settings()->getOrCreateForUser($this->member);
        $settings->notificationsPaused = true;
        $this->entityManager->flush();

        $this->runDigests();

        self::assertSame(
            [],
            $this->mailed,
        );
        self::assertSame(
            [],
            $this->pending()->findForUser($this->member),
        );
    }

    /**
     * An hourly digest is sent on the hour, not with every notification; what is not due yet waits.
     */
    public function testACategoryThatIsNotDueYetKeepsItsNotificationsQueued(): void
    {
        $this->queue(503);
        $this->subscribe(
            NotificationEmailFrequency::Hourly,
            new DateTimeImmutable(),
        );

        $this->runDigests();

        self::assertSame(
            [],
            $this->mailed,
        );
        self::assertCount(
            1,
            $this->pending()->findForUser($this->member),
        );
    }

    /**
     * Email is opt-in per category, so a notification in a category the member did not ask for waits for a later run.
     */
    public function testACategoryTheMemberDidNotOptIntoIsLeftAlone(): void
    {
        $this->queue(504);
        $this->subscribe(
            NotificationEmailFrequency::Immediately,
            category: NotificationType::SignupClosing,
        );

        $this->runDigests();

        self::assertSame(
            [],
            $this->mailed,
        );
        self::assertCount(
            1,
            $this->pending()->findForUser($this->member),
        );
    }

    /**
     * A notification about something removed has no target, so it is dropped and the category is not marked as sent.
     */
    public function testANotificationWhoseSubjectIsGoneIsDroppedWithoutMail(): void
    {
        $this->queue(505);
        $subscription = $this->subscribe(NotificationEmailFrequency::Immediately);

        $command = new RunNotificationDigestsCommand(
            $this->pending(),
            $this->subscriptions(),
            $this->settings(),
            $this->subjectResolver(resolves: false),
            $this->bus(),
            $this->entityManager,
            $this->translator(),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(
            [],
            $this->mailed,
        );
        self::assertSame(
            [],
            $this->pending()->findForUser($this->member),
        );
        self::assertNull(
            $this->reread($subscription)->lastSentAt,
        );
    }

    private function runDigests(): void
    {
        $command = new RunNotificationDigestsCommand(
            $this->pending(),
            $this->subscriptions(),
            $this->settings(),
            $this->subjectResolver(resolves: true),
            $this->bus(),
            $this->entityManager,
            $this->translator(),
        );

        $tester = new CommandTester($command);
        self::assertSame(
            0,
            $tester->execute([]),
        );
        self::assertMatchesRegularExpression(
            '/Queued \d+ notification digest/',
            $tester->getDisplay(),
        );
    }

    private function queue(int $subjectId): PendingNotificationEmail
    {
        $notification = new Notification();
        $notification->type = NotificationType::AlbumPublished;
        $notification->subjectId = $subjectId;
        $notification->createdAt = new DateTimeImmutable();

        $pending = new PendingNotificationEmail(
            $this->member,
            $notification,
        );

        $this->entityManager->persist($notification);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        return $pending;
    }

    private function subscribe(
        NotificationEmailFrequency $frequency,
        ?DateTimeImmutable $lastSentAt = null,
        NotificationType $category = NotificationType::AlbumPublished,
    ): NotificationEmailSubscription {
        $subscription = new NotificationEmailSubscription(
            $this->member,
            $category,
            $frequency,
        );
        $subscription->lastSentAt = $lastSentAt;

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    /**
     * The resolver runs over a stand-in for the album namer, so the test covers whether a subject still has a name,
     * not how an album is named.
     */
    private function subjectResolver(bool $resolves): NotificationSubjectResolver
    {
        $namer = self::createStub(NotificationSubjectNamerInterface::class);
        $namer->method('supports')->willReturnCallback(
            static fn (NotificationType $type): bool => NotificationType::AlbumPublished === $type,
        );
        $namer->method('namesFor')->willReturnCallback(
            static function (NotificationType $type, array $subjectIds) use ($resolves): array {
                if (!$resolves) {
                    return [];
                }

                $names = [];
                foreach ($subjectIds as $subjectId) {
                    $names[(int) $subjectId] = [
                        'en' => 'Party 2026',
                        'nl' => 'Feest 2026',
                    ];
                }

                return $names;
            },
        );

        return new NotificationSubjectResolver([$namer]);
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                self::assertInstanceOf(
                    SendNotificationDigestMessage::class,
                    $message,
                );
                $this->mailed[] = $message;

                return new Envelope($message);
            },
        );

        return $bus;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(
            TranslatorInterface::class,
            $translator,
        );

        return $translator;
    }

    private function reread(NotificationEmailSubscription $subscription): NotificationEmailSubscription
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->getRepository(NotificationEmailSubscription::class)->find($subscription->id);
        self::assertInstanceOf(
            NotificationEmailSubscription::class,
            $fresh,
        );

        return $fresh;
    }

    private function pending(): PendingNotificationEmailRepository
    {
        return self::getContainer()->get(PendingNotificationEmailRepository::class);
    }

    private function subscriptions(): NotificationEmailSubscriptionRepository
    {
        return self::getContainer()->get(NotificationEmailSubscriptionRepository::class);
    }

    private function settings(): UserSettingsRepository
    {
        return self::getContainer()->get(UserSettingsRepository::class);
    }
}
