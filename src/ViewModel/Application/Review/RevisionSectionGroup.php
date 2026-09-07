<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

use Symfony\Contracts\Translation\TranslatableInterface;

final readonly class RevisionSectionGroup
{
    public function __construct(
        public string $id,
        public string $label,
        public TranslatableInterface $collectionLabel,
        public RevisionChangeKind $kind = RevisionChangeKind::Same,
    ) {
    }
}
