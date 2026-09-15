<?php

declare(strict_types=1);

namespace App\Entity\Photo;

use DateTimeImmutable;

/**
 * Contains all photos of the week in a certain year. This is a VirtualAlbum, meaning that it is not persisted.
 */
class WeeklyAlbum extends VirtualAlbum
{
    /**
     * @param DateTimeImmutable[] $dates
     */
    public function __construct(
        int $id,
        private readonly array $dates,
    ) {
        parent::__construct($id);
    }

    /**
     * @return DateTimeImmutable[]
     */
    public function getDates(): array
    {
        return $this->dates;
    }
}
