<?php

declare(strict_types=1);

namespace App\ViewModel\Activity\Admin;

/**
 * One person on the sign-ups page read across every list of the activity: where they stand on each, and what they
 * answered wherever they were asked.
 */
final readonly class SignupPersonRow
{
    /**
     * @param array<int, string>    $statuses  per list id: "admitted", "waiting" or "signed" (a list without a limit
     *                                         admits everybody), absent when they are not in that list
     * @param list<int>             $signupIds every sign-up of theirs on this activity
     * @param array<string, string> $answers   per "listId:fieldId", what they answered there
     */
    public function __construct(
        public string $key,
        public int $position,
        public string $fullName,
        public string $membershipTypeLabel,
        public ?int $generation,
        public bool $external,
        public bool $organisingBody,
        public ?string $email,
        public array $statuses,
        public int $listCount,
        public array $signupIds,
        public array $answers,
        public bool $waitingSomewhere,
        public bool $selected,
    ) {
    }
}
