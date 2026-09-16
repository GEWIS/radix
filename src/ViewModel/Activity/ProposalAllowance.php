<?php

declare(strict_types=1);

namespace App\ViewModel\Activity;

use App\Entity\Activity\Enums\ProposalLimitSource;

use function max;

/**
 * How many activities a body may still put forward in one option period, and which rule set that number.
 *
 * The rule is recorded alongside the number because a body that may propose no more needs to know which rule decided
 * that. The old calendar showed zero with no explanation, and zero was usually not a decision anybody had made.
 */
final readonly class ProposalAllowance
{
    public function __construct(
        public int $maximum,
        public int $used,
        public ProposalLimitSource $source,
    ) {
    }

    public function remaining(): int
    {
        return max(
            0,
            $this->maximum - $this->used,
        );
    }

    public function isExhausted(): bool
    {
        return 0 === $this->remaining();
    }
}
