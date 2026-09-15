<?php

declare(strict_types=1);

namespace App\Entity\Decision\SubDecision;

use App\Doctrine\Query\Queryable;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Member;
use App\Entity\Decision\SubDecision;
use App\Entity\Decision\Traits\MemberAwareTrait;
use App\Repository\Decision\SubDecision\OrganRegulationRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

use function assert;

#[Entity(repositoryClass: OrganRegulationRepository::class)]
#[Queryable]
class OrganRegulation extends SubDecision
{
    use MemberAwareTrait;

    /**
     * Abbreviation of the organ.
     */
    #[Column(type: Types::STRING)]
    public string $abbr;

    /**
     * Type of the organ.
     */
    #[Column(
        type: Types::STRING,
        enumType: OrganTypes::class,
    )]
    private OrganTypes $organType;

    /**
     * Version of the regulation.
     */
    #[Column(
        type: Types::STRING,
        length: 32,
    )]
    public string $version;

    /**
     * Date of the regulation.
     */
    #[Column(type: Types::DATE_IMMUTABLE)]
    public DateTimeImmutable $date;

    /**
     * If the regulation was approved.
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

    /**
     * Set the organ type
     */
    public function setOrganType(OrganTypes $organType): void
    {
        $this->organType = $organType;
    }
}
