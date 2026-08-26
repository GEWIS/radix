<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision\Board;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\Member;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\Board\CandidateRepository;
use Doctrine\ORM\Mapping\Entity;

use function assert;

/**
 * One candidate the board puts forward.
 */
#[Entity(repositoryClass: CandidateRepository::class)]
#[Queryable]
class Candidate extends SubDecision
{
    use MemberAwareTrait;

    /**
     * Get the candidate.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; a candidacy always names its candidate.
        assert(null !== $this->member);

        return $this->member;
    }
}
