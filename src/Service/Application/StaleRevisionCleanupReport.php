<?php

declare(strict_types=1);

namespace App\Service\Application;

/**
 * What one run of {@see StaleRevisionCleaner} came to: the same five numbers whether it was allowed to change
 * anything or not, so a dry run reports exactly what a real run would have done.
 */
final readonly class StaleRevisionCleanupReport
{
    public function __construct(
        /** Heads discarded in favour of the version that is live. */
        public int $reverted,
        /** Never-approved aggregates removed whole, with their chains. */
        public int $deleted,
        /**
         * How many of {@see self::$deleted} only went because the run was forced. Counted separately because these
         * are the removals nobody asked for on a schedule, and an operator reading the summary should see whether
         * their `--force` did anything at all.
         */
        public int $forced,
        /** Aggregates left standing because their domain vetoed the removal. */
        public int $skipped,
        /** Stored files nothing pointed at any more once the rows were gone. */
        public int $filesReclaimed,
    ) {
    }
}
