<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\Member;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\MinutesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

use function assert;

/**
 * Decisions on minutes.
 */
#[Entity(repositoryClass: MinutesRepository::class)]
#[Queryable]
class Minutes extends SubDecision
{
    use MemberAwareTrait;

    /**
     * Reference to the meetings
     */
    #[OneToOne(
        targetEntity: Meeting::class,
        inversedBy: 'minutes',
    )]
    #[JoinColumn(
        name: 'r_meeting_type',
        referencedColumnName: 'type',
    )]
    #[JoinColumn(
        name: 'r_meeting_number',
        referencedColumnName: 'number',
    )]
    public Meeting $meeting;

    /**
     * If the minutes were approved.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $approval;

    /**
     * If there were changes made.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $changes;

    /**
     * Get the member.
     */
    public function getMember(): Member
    {
        // The trait keeps the association nullable for mapping reasons; this sub-decision always names a member.
        assert(null !== $this->member);

        return $this->member;
    }
}
