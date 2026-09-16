<?php

declare(strict_types=1);

namespace App\Entity\Application\Traits;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\PreUpdate;

/**
 * A trait which can be used to keep track of when changes where made to an entity.
 *
 * Requires the usage of {@link HasLifecycleCallbacks} on the entity using this trait.
 */
trait TimestampableTrait
{
    /**
     * The date at which the entity was created.
     */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * The date at which the entity was updated.
     */
    // Written by the class that is persisted rather than by the one that declares the column: the timestamp on an
    // audit entry is set by its concrete subclass.
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public protected(set) DateTimeImmutable $updatedAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Automatically fill in the `DateTimeImmutable`s before the initial call to `persist()`.
     */
    #[PrePersist]
    public function prePersist(): void
    {
        $now = new DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Automatically update the `updatedAt` `DateTimeImmutable` when doing an update to the entity.
     */
    #[PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
