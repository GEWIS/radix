<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

final readonly class ReviewStep
{
    public function __construct(
        public string $key,
        public string $label,
        public int $position,
        public int $changeCount,
        public bool $active,
        public bool $passed = false,
        public ?string $sub = null,
    ) {
    }
}
