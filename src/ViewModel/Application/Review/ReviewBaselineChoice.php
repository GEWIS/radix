<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

final readonly class ReviewBaselineChoice
{
    public function __construct(
        public string $key,
        public int $revisionNumber,
        public bool $live,
        public bool $current,
    ) {
    }
}
