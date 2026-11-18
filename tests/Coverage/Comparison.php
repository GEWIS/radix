<?php

declare(strict_types=1);

namespace App\Tests\Coverage;

use function round;
use function usort;

/**
 * The difference between two coverage runs.
 *
 * The comparison uses the project total. The per-file list only shows where a loss occurred.
 */
final readonly class Comparison
{
    /**
     * @param list<array{file: string, lost: int, current: float, baseline: float}> $dropped worst first
     */
    private function __construct(
        public float $current,
        public float $baseline,
        public array $dropped,
    ) {
    }

    public static function of(
        Summary $current,
        Summary $baseline,
    ): self {
        $dropped = [];

        foreach ($baseline->files as $file => $counts) {
            // A file the current run does not report was deleted or renamed, so it cannot be compared.
            $now = $current->files[$file] ?? null;
            if (null === $now) {
                continue;
            }

            $lost = $counts['covered'] - $now['covered'];
            if ($lost <= 0) {
                continue;
            }

            $dropped[] = [
                'file' => $file,
                'lost' => $lost,
                'current' => 0 === $now['statements'] ? 0.0 : round(
                    100 * $now['covered'] / $now['statements'],
                    2,
                ),
                'baseline' => 0 === $counts['statements'] ? 0.0 : round(
                    100 * $counts['covered'] / $counts['statements'],
                    2,
                ),
            ];
        }

        usort(
            $dropped,
            static function (array $left, array $right): int {
                if ($left['lost'] !== $right['lost']) {
                    return $right['lost'] <=> $left['lost'];
                }

                return $left['file'] <=> $right['file'];
            },
        );

        return new self(
            $current->percentage(),
            $baseline->percentage(),
            $dropped,
        );
    }

    /**
     * Percentage points lost, negative when coverage went up.
     */
    public function drop(): float
    {
        return $this->baseline - $this->current;
    }

    public function exceeds(float $allowedDrop): bool
    {
        // Two ratios leave floating-point noise far below the threshold, so a small epsilon absorbs it.
        return $this->drop() > $allowedDrop + 1e-6;
    }
}
