<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

final readonly class ReviewGroupLink
{
    public function __construct(
        public string $key,
        public string $label,
        public int $position,
        public int $changeCount,
        public bool $current,
    ) {
    }
}
