<?php

declare(strict_types=1);

namespace App\ViewModel\Application\Review;

use Symfony\Contracts\Translation\TranslatorInterface;

use function array_keys;
use function array_search;
use function array_values;
use function count;

final readonly class ReviewOutline
{
    /**
     * @param list<ReviewStep> $topSteps
     */
    private function __construct(
        public array $topSteps,
        public ?ReviewGroupBar $groupBar,
        public string $activeKey,
        public int $changeCount,
    ) {
    }

    /**
     * @param list<RevisionSection> $sections
     */
    public static function of(
        array $sections,
        string $activeKey,
        TranslatorInterface $translator,
    ): self {
        $families = [];
        $changes = 0;

        foreach ($sections as $section) {
            $changes += $section->changeCount();

            if (null === $section->group) {
                continue;
            }

            $id = $section->group->id;
            $families[$id] ??= [
                'group' => $section->group,
                'sections' => [],
            ];
            $families[$id]['sections'][] = $section;
        }

        $active = self::sectionFor(
            $sections,
            $activeKey,
        );

        return new self(
            self::topSteps(
                $sections,
                $families,
                $active,
                $translator,
            ),
            self::groupBar(
                $families,
                $active,
                $translator,
            ),
            null === $active ? $activeKey : $active->key,
            $changes,
        );
    }

    /**
     * @param list<RevisionSection> $sections
     */
    public static function sectionFor(
        array $sections,
        string $activeKey,
    ): ?RevisionSection {
        foreach ($sections as $section) {
            if ($section->key !== $activeKey) {
                continue;
            }

            return $section;
        }

        return $sections[0] ?? null;
    }

    /**
     * @param list<RevisionSection>                                                              $sections
     * @param array<string, array{group: RevisionSectionGroup, sections: list<RevisionSection>}> $families
     *
     * @return list<ReviewStep>
     */
    private static function topSteps(
        array $sections,
        array $families,
        ?RevisionSection $active,
        TranslatorInterface $translator,
    ): array {
        $steps = [];
        $familyDrawn = false;
        $position = 0;
        $beforeActive = true;

        foreach ($sections as $section) {
            if (null !== $section->group) {
                if ($familyDrawn) {
                    continue;
                }

                $familyDrawn = true;
                $step = self::familyStep(
                    $families,
                    $active,
                    ++$position,
                    $beforeActive,
                    $translator,
                );
            } else {
                $isActive = null !== $active && $active->key === $section->key;
                $step = new ReviewStep(
                    $section->key,
                    $section->heading->trans($translator),
                    ++$position,
                    $section->changeCount(),
                    $isActive,
                    $beforeActive && !$isActive,
                );
            }

            if ($step->active) {
                $beforeActive = false;
            }

            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * @param array<string, array{group: RevisionSectionGroup, sections: list<RevisionSection>}> $families
     */
    private static function familyStep(
        array $families,
        ?RevisionSection $active,
        int $position,
        bool $beforeActive,
        TranslatorInterface $translator,
    ): ReviewStep {
        $ids = array_keys($families);
        $first = $families[$ids[0]];

        if (
            null === $active
            || null === $active->group
        ) {
            return new ReviewStep(
                $first['sections'][0]->key,
                $first['group']->collectionLabel->trans($translator),
                $position,
                self::countOf($families),
                false,
                $beforeActive,
                sub: $translator->trans(
                    '%count% of them',
                    ['%count%' => count($ids)],
                ),
            );
        }

        $current = $active->group;
        $index = (int) array_search(
            $current->id,
            $ids,
            true,
        );

        return new ReviewStep(
            $active->key,
            $current->label,
            $position,
            self::countIn($families[$current->id]['sections']),
            true,
            sub: $translator->trans(
                '%position% of %total%',
                [
                    '%position%' => $index + 1,
                    '%total%' => count($ids),
                ],
            ),
        );
    }

    /**
     * @param array<string, array{group: RevisionSectionGroup, sections: list<RevisionSection>}> $families
     */
    private static function groupBar(
        array $families,
        ?RevisionSection $active,
        TranslatorInterface $translator,
    ): ?ReviewGroupBar {
        if (
            null === $active
            || null === $active->group
        ) {
            return null;
        }

        $current = $active->group;
        $ids = array_keys($families);
        $index = (int) array_search(
            $current->id,
            $ids,
            true,
        );

        $steps = [];
        $place = 0;
        $part = null;
        foreach ($families[$current->id]['sections'] as $section) {
            ++$place;

            if ($section->key === $active->key) {
                $part = $place;
            }

            $steps[] = new ReviewStep(
                $section->key,
                $section->heading->trans($translator),
                $place,
                $section->changeCount(),
                $section->key === $active->key,
            );
        }

        $siblings = [];
        $seat = 0;
        foreach ($families as $id => $family) {
            ++$seat;

            $siblings[] = new ReviewGroupLink(
                ($family['sections'][($part ?? 1) - 1] ?? $family['sections'][0])->key,
                $family['group']->label,
                $seat,
                self::countIn($family['sections']),
                $id === $current->id,
            );
        }

        return new ReviewGroupBar(
            $current,
            $index + 1,
            count($ids),
            $steps,
            $siblings,
            self::neighbour(
                $families,
                $ids,
                $index - 1,
                $part,
            ),
            self::neighbour(
                $families,
                $ids,
                $index + 1,
                $part,
            ),
        );
    }

    /**
     * @param array<string, array{group: RevisionSectionGroup, sections: list<RevisionSection>}> $families
     * @param list<string>                                                                       $ids
     */
    private static function neighbour(
        array $families,
        array $ids,
        int $index,
        ?int $part,
    ): ?string {
        if (!isset($ids[$index])) {
            return null;
        }

        $sections = $families[$ids[$index]]['sections'];

        return ($sections[($part ?? 1) - 1] ?? $sections[0])->key;
    }

    /**
     * @param array<string, array{group: RevisionSectionGroup, sections: list<RevisionSection>}> $families
     */
    private static function countOf(array $families): int
    {
        $count = 0;
        foreach ($families as $family) {
            $count += self::countIn($family['sections']);
        }

        return $count;
    }

    /**
     * @param list<RevisionSection> $sections
     */
    private static function countIn(array $sections): int
    {
        $count = 0;
        foreach ($sections as $section) {
            $count += $section->changeCount();
        }

        return $count;
    }

    /**
     * @param list<RevisionSection> $sections
     */
    public static function nextChangeAfter(
        array $sections,
        string $activeKey,
    ): ?string {
        $keys = [];
        foreach ($sections as $section) {
            $keys[] = $section->key;
        }

        $at = array_search(
            $activeKey,
            $keys,
            true,
        );

        foreach (array_values($sections) as $place => $section) {
            if (
                false !== $at
                && $place <= $at
            ) {
                continue;
            }

            if (!$section->hasChanges()) {
                continue;
            }

            return $section->key;
        }

        return null;
    }
}
