<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

final readonly class RevisionEntryOption
{
    public function __construct(
        public RevisionChangeKind $kind,
        public RevisionField $value,
    ) {
    }
}
