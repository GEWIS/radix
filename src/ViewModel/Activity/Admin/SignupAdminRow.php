<?php

declare(strict_types=1);

namespace App\ViewModel\Activity\Admin;

use DateTime;

final readonly class SignupAdminRow
{
    /**
     * @param list<array{value: string}>               $cells      one per sign-up field, already formatted and in
     *                                                             field order
     * @param list<string>                             $priority   why this subscriber ranks where they do, one label
     *                                                             per thing the list ranks on, in the order the draw
     *                                                             weighs them
     * @param list<array{name: string, waiting: bool}> $otherLists the activity's other lists this person is in too,
     *                                                             and whether they are still waiting there
     */
    public function __construct(
        public int $signupId,
        public int $position,
        public string $fullName,
        // "User (ordinary)" / "User (external)" for a member, "External" for a non-member sign-up.
        public string $membershipTypeLabel,
        // The member's generation, or null for an external sign-up.
        public ?int $generation,
        public bool $external,
        public ?string $email,
        public DateTime $signedUpAt,
        public bool $present,
        public bool $drawn,
        public array $cells,
        public array $priority = [],
        public ?int $roleId = null,
        public ?string $roleName = null,
        // Installed in the organ organising this activity, so a place was held off the top for them. Not a tier
        // somebody is ranked in, which is why it is said apart from the priority labels.
        public bool $organisingBody = false,
        public array $otherLists = [],
        public bool $selected = false,
    ) {
    }
}
