<?php

declare(strict_types=1);

namespace App\Entity\Application;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\Application\AnnouncementRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * A sticky site announcement: a banner shown at the top of every page until {@see $endsAt}. Only sticky broadcasts are
 * persisted; a one-off (non-sticky) broadcast is just a transient toast. Text is frozen at creation.
 */
#[Entity(repositoryClass: AnnouncementRepository::class)]
class Announcement
{
    use IdentifiableTrait;

    #[Column(
        type: Types::STRING,
        length: 16,
        enumType: AlertTypes::class,
    )]
    public AlertTypes $level = AlertTypes::Info;

    #[OneToOne(
        targetEntity: ApplicationLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'title_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ApplicationLocalisedText $title;

    #[OneToOne(
        targetEntity: ApplicationLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'body_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public ApplicationLocalisedText $body;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $endsAt;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdAt;
}
