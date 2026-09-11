<?php

declare(strict_types=1);

namespace App\Entity\Application;

use App\Entity\Application\Enums\MaintenanceStatus;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\Application\MaintenanceWindowRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * A scheduled maintenance window. Several may be planned as long as they do not overlap; the one covering the current
 * moment (if any) puts the site into that {@see MaintenanceStatus}. An open start means "from now", an open end means
 * "until turned off".
 */
#[Entity(repositoryClass: MaintenanceWindowRepository::class)]
class MaintenanceWindow
{
    use IdentifiableTrait;

    #[Column(
        type: Types::STRING,
        length: 16,
        enumType: MaintenanceStatus::class,
    )]
    public MaintenanceStatus $status = MaintenanceStatus::ReadOnly;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $startsAt = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public ?DateTimeImmutable $endsAt = null;

    public function isActiveAt(DateTimeImmutable $now): bool
    {
        return (null === $this->startsAt || $now >= $this->startsAt)
            && (null === $this->endsAt || $now < $this->endsAt);
    }

    /**
     * Whether this window's half-open interval overlaps another's. A null bound is an open end of the timeline, so a
     * window with neither bound covers all of time.
     */
    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = null === $this->startsAt
            || null === $other->endsAt
            || $this->startsAt < $other->endsAt;
        $otherStartsBeforeThisEnds = null === $other->startsAt
            || null === $this->endsAt
            || $other->startsAt < $this->endsAt;

        return $startsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }
}
