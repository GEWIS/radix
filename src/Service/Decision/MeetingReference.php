<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Database\Enums\MeetingTypes;

/**
 * A meeting, a point in it or a single decision, as a search prompt addresses it: "BV 1749", "GMM 214.3" or
 * "meeting:1749.3.1". The type is null when the prompt gave a number without one.
 */
final readonly class MeetingReference
{
    public function __construct(
        public ?MeetingTypes $type,
        public int $number,
        public ?int $point = null,
        public ?int $decision = null,
    ) {
    }

    public function withType(?MeetingTypes $type): self
    {
        return new self(
            $type,
            $this->number,
            $this->point,
            $this->decision,
        );
    }
}
