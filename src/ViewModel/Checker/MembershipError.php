<?php

declare(strict_types=1);

namespace App\ViewModel\Checker;

use App\Entity\Database\Enums\MembershipProblems;
use App\Entity\Database\Member as MemberModel;
use App\Entity\Database\Membership as MembershipModel;

use function sprintf;

/**
 * About a member rather than a decision, which is why it is not an {@see Error}.
 */
final readonly class MembershipError
{
    public function __construct(
        private MemberModel $member,
        private MembershipProblems $problem,
        private MembershipModel $membership,
        private ?MembershipModel $other = null,
    ) {
    }

    public function getMember(): MemberModel
    {
        return $this->member;
    }

    public function getProblem(): MembershipProblems
    {
        return $this->problem;
    }

    public function asText(): string
    {
        $membership = $this->describe($this->membership);

        if (null !== $this->other) {
            $membership .= ' and ' . $this->describe($this->other);
        }

        return sprintf(
            '%s: %d (%s) -- %s',
            $this->problem->getName()->getMessage(),
            $this->member->getLidnr(),
            $this->member->getFullName(),
            $membership,
        );
    }

    private function describe(MembershipModel $membership): string
    {
        return sprintf(
            '%s %s to %s',
            $membership->getType()->value,
            $membership->getStartDate()->format('Y-m-d'),
            $membership->getEndDate()->format('Y-m-d'),
        );
    }
}
