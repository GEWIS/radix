<?php

declare(strict_types=1);

namespace App\Twig\Components\Application;

use App\Entity\Application\LabelInterface;
use App\Repository\Application\LabelRepositoryInterface;

use function array_intersect;
use function array_map;
use function array_values;

/**
 * The label filter of an overview: the labels the panel offers, and the ids a filter may still hold.
 */
trait FiltersLabelsTrait
{
    /** @var LabelInterface[]|null */
    private ?array $labels = null;

    abstract protected function labelRepository(): LabelRepositoryInterface;

    /**
     * The filter panel reads this twice (once to check whether to render the block, once for the checkboxes), so it is
     * fetched once per render, with the localised names the checkboxes are labelled with.
     *
     * @return LabelInterface[]
     */
    public function getLabels(): array
    {
        return $this->labels ??= $this->labelRepository()->findActiveWithName();
    }

    /**
     * A retired label is no longer offered, so an id that names one is dropped rather than left filtering the list.
     * The panel is `data-live-ignore` and shows only the labels that are offered, so a filter it cannot show is one
     * the reader cannot clear.
     *
     * @param int[] $ids
     *
     * @return int[]
     */
    private function offeredLabelIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_values(array_intersect(
            $ids,
            array_map(
                static fn (LabelInterface $label): int => (int) $label->id,
                $this->getLabels(),
            ),
        ));
    }
}
