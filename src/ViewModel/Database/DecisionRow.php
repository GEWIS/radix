<?php

declare(strict_types=1);

namespace App\ViewModel\Database;

/**
 * One decision as it reads in a meeting's decision list.
 *
 * The content arrives assembled, because reading a decision needs a translator that a template cannot hand the
 * entity. It is one text rather than a sentence per subdecision: a decision reads as a whole, and splitting it apart
 * makes a decision of several subdecisions look like several decisions.
 */
final readonly class DecisionRow
{
    /**
     * @param ?DecisionReference  $counterpart         what a virtual decision is the counterpart of
     * @param DecisionReference[] $virtualCounterparts the virtual decisions that are this one's counterpart
     */
    public function __construct(
        public int $point,
        public int $number,
        public string $content,
        public string $copyContent,
        public ?DecisionReference $annulment,
        public ?DecisionReference $counterpart,
        public array $virtualCounterparts,
    ) {
    }
}
