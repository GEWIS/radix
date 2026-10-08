<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Panel-pager navigation: one item at a time, index wrapped to the range, no dead ends.
 */
trait PanelPagerTrait
{
    #[LiveProp]
    public int $index = 0;

    /**
     * How many items are being paged. Subclasses provide their own list.
     */
    abstract protected function pagedItemCount(): int;

    #[LiveAction]
    public function show(
        #[LiveArg]
        int $at,
    ): void {
        $count = $this->pagedItemCount();

        // Wraps, so neither control is ever dead.
        $this->index = (($at % $count) + $count) % $count;
    }

    public function total(): int
    {
        return $this->pagedItemCount();
    }
}
