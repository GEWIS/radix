<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\User;

use App\Entity\Application\Enums\NotificationEmailFrequency;
use App\Entity\Application\Enums\NotificationType;
use App\Entity\User\DataExportRequest;
use App\Entity\User\User;
use App\Entity\User\UserSettings;
use App\Repository\User\DataExportRequestRepository;
use App\Repository\User\NotificationEmailSubscriptionRepository;
use App\Repository\User\UserSettingsRepository;
use App\Service\User\UserSettingsService;
use App\Tests\Integration\DatabaseTestCase;

/**
 * A member's own settings. Pausing notifications and subscribing to categories are two tables, so they have to commit
 * together: a member who paused the mail but whose subscriptions did not save would keep receiving it.
 */
final class UserSettingsServiceTest extends DatabaseTestCase
{
    public function testTheNotificationPreferencesCommitTogether(): void
    {
        $user = $this->user();
        $settings = $this->settings($user);

        $this->service()->saveNotificationPreferences(
            $user,
            $settings,
            [NotificationType::ActivityPublished->value => NotificationEmailFrequency::Daily],
            true,
        );

        self::assertTrue($settings->notificationsPaused);

        $subscriptions = self::getContainer()->get(NotificationEmailSubscriptionRepository::class)->findForUser($user);
        self::assertCount(
            1,
            $subscriptions,
        );
    }

    public function testCosmeticsCanBeTurnedOffAndBackOn(): void
    {
        $user = $this->user();
        $settings = $this->settings($user);

        $this->service()->setCosmeticsDisabled(
            $settings,
            true,
        );
        self::assertTrue($settings->disableCosmetics);

        $this->service()->setCosmeticsDisabled(
            $settings,
            false,
        );
        self::assertFalse($settings->disableCosmetics);
    }

    public function testTheSettingsRowItselfIsSaved(): void
    {
        $user = $this->user();
        $lidnr = $user->lidnr;
        $settings = $this->settings($user);
        $settings->photoTaggingOptOut = true;

        $this->service()->save($settings);

        $this->entityManager->clear();
        $stored = self::getContainer()->get(UserSettingsRepository::class)->find($lidnr);
        self::assertInstanceOf(
            UserSettings::class,
            $stored,
        );
        self::assertTrue($stored->photoTaggingOptOut);
    }

    /**
     * The export is a row a worker picks up, so the request has to outlive the request that made it.
     */
    public function testRequestingADataExportLeavesARowForTheWorker(): void
    {
        $user = $this->user();

        $this->service()->requestDataExport($user);

        $request = self::getContainer()->get(DataExportRequestRepository::class)->findLatestForUser($user);
        self::assertInstanceOf(
            DataExportRequest::class,
            $request,
        );
        self::assertSame(
            $user,
            $request->user,
        );
    }

    private function service(): UserSettingsService
    {
        return self::getContainer()->get(UserSettingsService::class);
    }

    private function settings(User $user): UserSettings
    {
        $repository = self::getContainer()->get(UserSettingsRepository::class);
        $settings = $repository->find($user->lidnr);

        if (null === $settings) {
            // A member only gets a row the first time they touch their settings.
            $settings = new UserSettings($user);
            $this->entityManager->persist($settings);
            $this->entityManager->flush();
        }

        return $settings;
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(8030);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a member.',
        );

        return $user;
    }
}
