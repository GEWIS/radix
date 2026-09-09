<?php

declare(strict_types=1);

namespace App\ViewModel\Application;

use DateTimeImmutable;

/**
 * One scheduled command that has not run when it should have, for the dashboard.
 *
 * The trigger is stored as the string it formats itself to rather than as a bare cron expression, so a trigger that
 * is decorated describes itself completely.
 */
final readonly class LateScheduledTask
{
    public function __construct(
        public string $command,
        public string $trigger,
        /** Null when nothing has been seen since this deployment first recorded anything. */
        public ?DateTimeImmutable $lastRun,
        public DateTimeImmutable $dueAt,
    ) {
    }
}
