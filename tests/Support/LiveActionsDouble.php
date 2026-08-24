<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Attribute\Application\ReadOnlySafe;
use Symfony\UX\LiveComponent\Attribute\LiveAction;

/**
 * A live component with one action of each kind, so what
 * {@see \App\EventListener\Application\MaintenanceListener} reads off an action is real rather than described.
 */
final class LiveActionsDouble
{
    #[LiveAction]
    #[ReadOnlySafe]
    public function loadMore(): void
    {
    }

    #[LiveAction]
    public function vote(): void
    {
    }
}
