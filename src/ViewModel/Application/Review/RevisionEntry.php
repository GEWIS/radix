<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

use Symfony\Contracts\Translation\TranslatableInterface;

use function array_filter;
use function array_values;

final readonly class RevisionEntry
{
    /**
     * @param list<RevisionField>       $fields
     * @param list<RevisionEntryOption> $options
     */
    public function __construct(
        public RevisionChangeKind $kind,
        public ?int $position,
        public string $title,
        public array $fields,
        public array $options = [],
        public ?TranslatableInterface $type = null,
        public ?TranslatableInterface $note = null,
    ) {
    }

    public function isOpenByDefault(): bool
    {
        return $this->kind->isChange();
    }

    /**
     * @return list<RevisionEntryOption>
     */
    public function changedOptions(): array
    {
        return array_values(array_filter(
            $this->options,
            static fn (RevisionEntryOption $option): bool => $option->kind->isChange(),
        ));
    }
}
