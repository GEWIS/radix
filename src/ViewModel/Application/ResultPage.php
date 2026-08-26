<?php

declare(strict_types=1);

namespace App\ViewModel\Application;

/**
 * One page of an overview: the rows on it and how many rows there are in total.
 *
 * This is all a paginated overview ever needs from whatever it lists. A Doctrine `Paginator` is one way of saying
 * it, an array a repository built by hand is another, and a list sliced in PHP because the source takes no offset is
 * a third; the paging itself does not care which, so it asks for this instead.
 *
 * @template T
 */
final readonly class ResultPage
{
    /** @param list<T> $rows */
    public function __construct(
        public array $rows,
        public int $total,
    ) {
    }
}
