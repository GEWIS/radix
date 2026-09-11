<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Notification;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\User\NotificationInteractionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * What one member has done with one notification: read it, or cleared it away.
 *
 * Both are per member rather than on the notification itself, because most notifications go out to everybody, and one
 * member clearing an album announcement must not clear it for the rest of the association.
 *
 * A row exists only once somebody acts on a single notification. Marking everything read still just stamps
 * {@see UserSettings::$notificationsReadAt}, so a member who never touches an individual notification never has a row
 * here at all.
 */
#[Entity(repositoryClass: NotificationInteractionRepository::class)]
#[UniqueConstraint(columns: [
    'user_id',
    'notification_id',
])]
class NotificationInteraction
{
    use IdentifiableTrait;

    #[ManyToOne(targetEntity: User::class)]
    #[JoinColumn(
        name: 'user_id',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public private(set) User $user;

    #[ManyToOne(targetEntity: Notification::class)]
    #[JoinColumn(
        name: 'notification_id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public private(set) Notification $notification;

    /**
     * When this member read this one notification, as opposed to marking the whole centre read.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $readAt = null;

    /**
     * When this member cleared it away. The notification itself stays; it is only hidden from them.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $dismissedAt = null;

    public function __construct(
        User $user,
        Notification $notification,
    ) {
        $this->user = $user;
        $this->notification = $notification;
    }
}
