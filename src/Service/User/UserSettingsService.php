<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Application\Enums\NotificationEmailFrequency;
use App\Entity\User\DataExportRequest;
use App\Entity\User\User;
use App\Entity\User\UserSettings;
use App\Repository\User\NotificationEmailSubscriptionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writing a member's own settings, and taking their request for a copy of their data.
 *
 * The notification screen is the reason this is a service rather than four flushes: which categories are subscribed to
 * lives in its own table and whether notifications are paused lives on the settings row, and a member who paused their
 * mail but whose subscriptions did not save would keep receiving it.
 */
final readonly class UserSettingsService
{
    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private EntityManagerInterface $entityManager,
        private NotificationEmailSubscriptionRepository $notificationSubscriptions,
    ) {
    }

    public function save(UserSettings $settings): void
    {
        $this->entityManager->flush();
    }

    /**
     * @param array<string, NotificationEmailFrequency> $frequencies keyed by {@see NotificationType} value
     */
    public function saveNotificationPreferences(
        User $user,
        UserSettings $settings,
        array $frequencies,
        bool $paused,
    ): void {
        $this->notificationSubscriptions->setForUser(
            $user,
            $frequencies,
        );
        $settings->setNotificationsPaused($paused);

        $this->entityManager->flush();
    }

    public function setCosmeticsDisabled(
        UserSettings $settings,
        bool $disabled,
    ): void {
        $settings->setDisableCosmetics($disabled);

        $this->entityManager->flush();
    }

    /**
     * Take a member's request for a copy of their data. The worker picks it up from the row.
     */
    public function requestDataExport(User $user): void
    {
        $export = new DataExportRequest();
        $export->setUser($user);
        $export->setRequestedAt(new DateTimeImmutable());

        $this->entityManager->persist($export);
        $this->entityManager->flush();
    }
}
