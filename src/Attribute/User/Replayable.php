<?php

declare(strict_types=1);

namespace App\Attribute\User;

use Attribute;

/**
 * Marks an action whose submission is re-run once the user confirms sudo, instead of being returned to them to send
 * again.
 *
 * Opt-in, and deliberately so. Re-running a write without the user pressing anything is only right where the action
 * is the whole of the change and its outcome does not depend on what the page showed at the time. An action that
 * takes payment, writes to an external system or mails a list is not marked, and neither is one whose form the user
 * should see again before it happens.
 *
 * An unmarked action is not re-run: the user is returned to the page they submitted from
 * ({@see \App\Security\User\SudoStash}).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Replayable
{
}
