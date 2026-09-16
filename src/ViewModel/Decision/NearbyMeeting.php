<?php

declare(strict_types=1);

namespace App\ViewModel\Decision;

use App\Entity\Database\Enums\MeetingTypes;
use DateTimeImmutable;

/**
 * A row in the "Other meetings" sidebar. Deliberately not a {@see \App\Entity\Decision\Meeting}: hydrating the
 * entity also loads its one-to-one sides, and the sidebar only links.
 */
final readonly class NearbyMeeting
{
    public function __construct(
        public MeetingTypes $type,
        public int $number,
        public DateTimeImmutable $date,
    ) {
    }
}
