<?php

declare(strict_types=1);

namespace App\ViewModel\Application;

/**
 * What could be read off the failure transport, newest first.
 *
 * Not a page: the transport can only be asked for its first N messages and answers in no defined order, so the whole
 * readable set is fetched and sorted, and whatever pages over it slices this. {@see $transportTotal} is what the
 * transport says it holds, which parts company with the number of rows here once the inspection cap bites;
 * {@see $truncated} says when it has, because a table quietly ending at the cap reads as "that is all of them".
 */
final readonly class FailedMessageList
{
    /** @param list<FailedMessageRow> $rows */
    public function __construct(
        public array $rows,
        public ?int $transportTotal,
        public bool $truncated,
    ) {
    }
}
