<?php

declare(strict_types=1);

namespace App\ViewModel\Activity\Calendar;

use DateTimeImmutable;

/**
 * Something on the association's own agenda that the website does not store itself: an exam week, the intro week, a
 * booking made outside the website.
 *
 * Days rather than clock times, because the option calendar reserves days, and because most of what is only on
 * that agenda is a whole day or a run of them anyway.
 */
final readonly class AgendaEvent
{
    public function __construct(
        public string $title,
        public DateTimeImmutable $startsOn,
        public DateTimeImmutable $endsOn,
    ) {
    }
}
