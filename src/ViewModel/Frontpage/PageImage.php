<?php

declare(strict_types=1);

namespace App\ViewModel\Frontpage;

use DateTimeImmutable;

/** `$ready` is false while the sizes the website serves are still being rendered, which is when it cannot be placed. */
final readonly class PageImage
{
    public function __construct(
        public string $path,
        public DateTimeImmutable $uploadedAt,
        public bool $ready,
    ) {
    }
}
