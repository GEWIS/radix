<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Member;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\Member;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\Member\WarningRepository;
use Doctrine\ORM\Mapping\Entity;

use function assert;

/**
 * An official warning handed to a member by the board.
 */
#[Entity(repositoryClass: WarningRepository::class)]
#[Queryable]
class Warning extends SubDecision
{
    use MemberAwareTrait;

    /**
     * Get the member who is warned.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; a warning always names the member it is
        // handed to.
        assert(null !== $this->member);

        return $this->member;
    }
}
