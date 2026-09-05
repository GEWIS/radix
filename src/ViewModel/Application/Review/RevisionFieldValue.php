<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

use App\Entity\Application\Enums\Languages;
use DateTimeInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;

use function array_key_first;
use function array_map;
use function array_values;
use function is_array;
use function sort;

/**
 * What one field held before this revision and what it holds now, raw. Nothing here is rendered or translated yet,
 * which is what lets the same description serve the reviewer's screen and the author's.
 *
 * A field that exists once per language carries one of these per language; anything else carries a single value with
 * no language at all. Whether the old value means anything is {@see RevisionField::$comparable}, since a first
 * revision has nothing behind it and every old value is null for that reason alone.
 *
 * @phpstan-type RevisionValueSet = list<RevisionTag>|list<RevisionFlag>
 */
final readonly class RevisionFieldValue
{
    /**
     * @param string|bool|TranslatableInterface|RevisionDateRange|DateTimeInterface|RevisionValueSet|null $old
     * @param string|bool|TranslatableInterface|RevisionDateRange|DateTimeInterface|RevisionValueSet|null $new
     */
    public function __construct(
        public string|bool|TranslatableInterface|RevisionDateRange|DateTimeInterface|array|null $old,
        public string|bool|TranslatableInterface|RevisionDateRange|DateTimeInterface|array|null $new,
        public ?Languages $language = null,
    ) {
    }

    public function changeKind(bool $comparable): RevisionChangeKind
    {
        // A set of switches carries its own before and after inside each switch, so the value itself has no side that
        // is absent and would otherwise read as new every time.
        if (null !== self::flagsIn($this->new)) {
            return $comparable && $this->isChanged()
                ? RevisionChangeKind::Changed
                : RevisionChangeKind::Same;
        }

        $before = $comparable && $this->holds($this->old);
        $after = $this->holds($this->new);

        return match (true) {
            !$before && !$after => RevisionChangeKind::Same,
            !$before => RevisionChangeKind::Added,
            !$after => RevisionChangeKind::Removed,
            $this->isChanged() => RevisionChangeKind::Changed,
            default => RevisionChangeKind::Same,
        };
    }

    private function holds(mixed $value): bool
    {
        return match (true) {
            null === $value => false,
            '' === $value => false,
            [] === $value => false,
            $value instanceof RevisionDateRange => $value->holds(),
            default => true,
        };
    }

    public function isChanged(): bool
    {
        if (
            $this->old instanceof RevisionDateRange
            && $this->new instanceof RevisionDateRange
        ) {
            return !$this->old->equals($this->new);
        }

        if (
            $this->old instanceof DateTimeInterface
            && $this->new instanceof DateTimeInterface
        ) {
            return $this->old->getTimestamp() !== $this->new->getTimestamp();
        }

        // A translatable value is usually built fresh on every call, so identity says nothing about it. An enum that
        // is translatable is its own singleton and falls through to the comparison below, which is what it wants.
        if (
            $this->old instanceof TranslatableMessage
            && $this->new instanceof TranslatableMessage
        ) {
            return $this->old->getMessage() !== $this->new->getMessage()
                || $this->old->getParameters() !== $this->new->getParameters();
        }

        $flags = self::flagsIn($this->new);
        if (null !== $flags) {
            foreach ($flags as $flag) {
                if (
                    null === $flag->old
                    || $flag->old === $flag->new
                ) {
                    continue;
                }

                return true;
            }

            return false;
        }

        // Labels are a set, each kept with its identity, so another order is not a change to them. Fresh view models
        // are built on every read, which is why comparing the lists themselves says nothing.
        $before = self::tagIdsIn($this->old);
        $after = self::tagIdsIn($this->new);

        if (
            null !== $before
            || null !== $after
        ) {
            return ($before ?? []) !== ($after ?? []);
        }

        return $this->old !== $this->new;
    }

    /**
     * @return list<RevisionFlag>|null
     */
    private static function flagsIn(mixed $value): ?array
    {
        if (
            !is_array($value)
            || [] === $value
            || !$value[array_key_first($value)] instanceof RevisionFlag
        ) {
            return null;
        }

        return array_values($value);
    }

    /**
     * @return list<int|null>|null
     */
    private static function tagIdsIn(mixed $value): ?array
    {
        if (
            !is_array($value)
            || [] === $value
            || !$value[array_key_first($value)] instanceof RevisionTag
        ) {
            return null;
        }

        $ids = array_map(
            static fn (RevisionTag $tag): ?int => $tag->id,
            array_values($value),
        );
        sort($ids);

        return $ids;
    }
}
