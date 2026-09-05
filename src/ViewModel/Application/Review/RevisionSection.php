<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

use Symfony\Contracts\Translation\TranslatableInterface;

use function array_filter;
use function array_values;
use function count;

final readonly class RevisionSection
{
    /**
     * @param string              $key     names the section in an address, so a reviewer can be sent to it and come
     *                                     back to it; stable across revisions
     * @param list<RevisionField> $fields
     * @param list<RevisionEntry> $entries
     */
    public function __construct(
        public string $key,
        public TranslatableInterface $heading,
        public array $fields,
        public RevisionAudience $audience = RevisionAudience::Everyone,
        public array $entries = [],
        public ?RevisionSectionGroup $group = null,
        public ?string $description = null,
    ) {
    }

    /**
     * @return list<RevisionField>
     */
    public function localisedFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (RevisionField $field): bool => $field->isLocalised(),
        ));
    }

    /**
     * @return list<RevisionField>
     */
    public function plainFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (RevisionField $field): bool => !$field->isLocalised(),
        ));
    }

    public function changeCount(): int
    {
        if ([] !== $this->entries) {
            return count(array_filter(
                $this->entries,
                static fn (RevisionEntry $entry): bool => $entry->kind->isChange(),
            ));
        }

        return count(array_filter(
            $this->fields,
            static fn (RevisionField $field): bool => $field->changeKind()->isChange(),
        ));
    }

    public function itemCount(): int
    {
        return [] !== $this->entries
            ? count($this->entries)
            : count($this->fields);
    }

    public function hasChanges(): bool
    {
        return $this->changeCount() > 0;
    }

    /**
     * The same section with only the fields {@see $audience} is allowed to see, or null when that leaves nothing.
     */
    public function forAudience(RevisionAudience $audience): ?self
    {
        if (!$audience->canSee($this->audience)) {
            return null;
        }

        $fields = array_values(array_filter(
            $this->fields,
            static fn (RevisionField $field): bool => $audience->canSee($field->audience),
        ));

        if (
            [] === $fields
            && [] === $this->entries
            && null === $this->group
        ) {
            return null;
        }

        return new self(
            $this->key,
            $this->heading,
            $fields,
            $this->audience,
            $this->entries,
            $this->group,
            $this->description,
        );
    }
}
