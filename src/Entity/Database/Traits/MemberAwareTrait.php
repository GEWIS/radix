<?php

declare(strict_types=1);

namespace App\Entity\Database\Traits;

use App\Entity\Database\Member;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

trait MemberAwareTrait
{
    /**
     * The member involved in this sub-decision.
     *
     * Not all sub-decisions require this, and they share one column in one table, so it is nullable here. A
     * sub-decision that needs the guarantee that it is not null, or that has an inverse side to name, declares the
     * association itself instead of using this trait; {@see \App\Entity\Database\SubDecision\Installation} is the
     * one that does.
     */
    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        nullable: true,
    )]
    private ?Member $member = null;

    /**
     * Get the member.
     */
    public function getMember(): ?Member
    {
        return $this->member;
    }

    /**
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }
}
