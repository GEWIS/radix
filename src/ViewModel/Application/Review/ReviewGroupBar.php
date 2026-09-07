<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

final readonly class ReviewGroupBar
{
    /**
     * @param list<ReviewStep>      $steps    the record's own sections
     * @param list<ReviewGroupLink> $siblings every record of the family, this one included
     */
    public function __construct(
        public RevisionSectionGroup $group,
        public int $position,
        public int $total,
        public array $steps,
        public array $siblings,
        public ?string $previousKey = null,
        public ?string $nextKey = null,
    ) {
    }
}
