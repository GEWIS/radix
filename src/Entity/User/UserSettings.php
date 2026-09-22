<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\User\Enums\ColourVision;
use App\Entity\User\Enums\PhotoVisibility;
use App\Repository\User\UserSettingsRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Per-member, app-owned settings and privacy preferences.
 *
 * These are on the `User` side (keyed by `lidnr`, via a derived/shared identity to {@see User}) and never on
 * `Member`, because the `Member` table is projected from the ledger and read-only here. A member has at most one
 * row; a missing row means "all defaults", which is what {@see User::hasDisabledCosmetics()} and the reads beside it
 * apply by reading from the property of a row that may not be there, so rows are only created the first time a
 * member touches their settings.
 *
 * @phpstan-type UserSettingsGdprArrayType = array{
 *     disableCosmetics: bool,
 *     colourVision: string,
 *     photoTaggingOptOut: bool,
 *     photoVisibility: string,
 *     hideYearOfBirth: bool,
 *     hideBirthdayOnFrontpage: bool,
 *     notificationsPaused: bool,
 * }
 */
#[Entity(repositoryClass: UserSettingsRepository::class)]
class UserSettings
{
    /**
     * The member these settings belong to. Its `lidnr` is also this entity's primary key (derived identity), mirroring
     * how {@see User} keys itself off {@see \App\Entity\Decision\Member}.
     */
    #[Id]
    #[OneToOne(
        targetEntity: User::class,
        inversedBy: 'settings',
    )]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        onDelete: 'CASCADE',
    )]
    public private(set) User $user;

    /**
     * Whether to hide the festive cosmetics (balloons, snow, fireworks) for this member.
     */
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => false],
    )]
    public bool $disableCosmetics = false;

    /**
     * Which pair of colours a revision review marks additions and removals with.
     */
    #[Column(
        type: Types::STRING,
        enumType: ColourVision::class,
        options: ['default' => ColourVision::Default->value],
    )]
    public ColourVision $colourVision = ColourVision::Default;

    /**
     * Whether this member has opted out of being tagged in photos.
     */
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => false],
    )]
    public bool $photoTaggingOptOut = false;

    /**
     * How much of this member's tagged-photo collection is hidden from other members on their photo page.
     */
    #[Column(
        type: Types::STRING,
        enumType: PhotoVisibility::class,
        options: ['default' => PhotoVisibility::HideSelected->value],
    )]
    public PhotoVisibility $photoVisibility = PhotoVisibility::HideSelected;

    /**
     * Whether this member's year of birth (and thus age) is hidden from other members. Reciprocal: a member who hides
     * their own year of birth also stops seeing everyone else's.
     */
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => false],
    )]
    public bool $hideYearOfBirth = false;

    /**
     * Whether this member is excluded from the birthday panel on the home page.
     */
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => false],
    )]
    public bool $hideBirthdayOnFrontpage = false;

    /**
     * When this member last marked the notification centre read. Null means they have never opened it, so everything
     * counts as unread.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $notificationsReadAt = null;

    /**
     * Whether the member has paused all outgoing notification email. Website notifications keep working; nothing is
     * mailed until they turn this off. A blunt mute on top of the per-category email opt-ins.
     */
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => false],
    )]
    public bool $notificationsPaused = false;

    public function __construct(User $user)
    {
        $this->user = $user;
        $user->settings = $this;
    }

    /**
     * @return UserSettingsGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'disableCosmetics' => $this->disableCosmetics,
            'colourVision' => $this->colourVision->value,
            'photoTaggingOptOut' => $this->photoTaggingOptOut,
            'photoVisibility' => $this->photoVisibility->value,
            'hideYearOfBirth' => $this->hideYearOfBirth,
            'hideBirthdayOnFrontpage' => $this->hideBirthdayOnFrontpage,
            'notificationsPaused' => $this->notificationsPaused,
        ];
    }
}
