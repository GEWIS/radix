<?php

declare(strict_types=1);

namespace App\Message\Activity;

/**
 * The share cards of an activity are drawn from the revision that was just approved. The message contains the
 * revision rather than the activity, because it is dispatched before the approval is flushed: the revision's content
 * is already in the database, the activity's live revision is not yet repointed. Handled on the `images` transport.
 */
class RenderActivityShareImageMessage
{
    public function __construct(
        private readonly int $revisionId,
    ) {
    }

    public function getRevisionId(): int
    {
        return $this->revisionId;
    }
}
