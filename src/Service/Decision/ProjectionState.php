<?php

declare(strict_types=1);

namespace App\Service\Decision;

use Override;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the projection is following the ledger, and whether it is in the middle of doing so.
 *
 * Shared by the listeners that keep the projection level with the ledger. The container guarantees they all share the
 * same instance, which is what lets one of them disable the others.
 */
final class ProjectionState implements ResetInterface
{
    private bool $enabled = true;

    private bool $projecting = false;

    /** Reset after each request so a bulk load cannot disable projection for the next one. */
    #[Override]
    public function reset(): void
    {
        $this->enabled = true;
        $this->projecting = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Stop following the ledger. {@see \App\EventListener\Decision\BulkLoadListener} does this for a bulk load, after
     * which the projection is rebuilt in one pass instead.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Whether a projection is already under way.
     *
     * Generating into the projection flushes it, and that flush reaches the update listener again through the
     * entities it just wrote; this is what stops the recursion.
     */
    public function isProjecting(): bool
    {
        return $this->projecting;
    }

    public function beginProjecting(): void
    {
        $this->projecting = true;
    }

    public function endProjecting(): void
    {
        $this->projecting = false;
    }
}
