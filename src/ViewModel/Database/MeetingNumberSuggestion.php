<?php

declare(strict_types=1);

namespace App\ViewModel\Database;

use App\Entity\Database\Enums\MeetingTypes;
use DateTimeImmutable;

final readonly class MeetingNumberSuggestion
{
    /**
     * @param non-negative-int|null $latestNumber
     * @param int[]                 $missingNumbers
     */
    public function __construct(
        public MeetingTypes $type,
        public ?int $latestNumber,
        public ?DateTimeImmutable $latestDate,
        public array $missingNumbers,
    ) {
    }

    /**
     * A virtual meeting is dated back to the meeting it corrects.
     */
    public function datesFollowNumbers(): bool
    {
        return MeetingTypes::VIRT !== $this->type;
    }

    /**
     * @return positive-int
     */
    public function next(): int
    {
        return null === $this->latestNumber
            ? 1
            : $this->latestNumber + 1;
    }
}
