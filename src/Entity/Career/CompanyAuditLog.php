<?php

declare(strict_types=1);

namespace App\Entity\Career;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Career\Enums\CompanyAuditVerbs;
use App\Entity\User\CompanyUser as CompanyUserModel;
use App\Entity\User\User as UserModel;
use App\Repository\Career\CompanyAuditLogRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\PrePersist;
use LogicException;

/**
 * One entry of a company's administrative timeline: who was invited, which packages changed hands, what happened to the
 * banner. The action can come from either side of the arrangement, so the actor is a board member or one of the
 * company's own representatives, never both.
 */
#[Entity(repositoryClass: CompanyAuditLogRepository::class)]
#[HasLifecycleCallbacks]
#[Index(
    name: 'company_audit_log_created_idx',
    columns: ['createdAt'],
)]
class CompanyAuditLog
{
    use IdentifiableTrait;

    #[ManyToOne(targetEntity: Company::class)]
    #[JoinColumn(
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public Company $company;

    /**
     * The board or C4 member who acted. Mutually exclusive with {@see $actorCompanyUser}; both are null for something
     * the system did on its own.
     */
    #[ManyToOne(targetEntity: UserModel::class)]
    #[JoinColumn(
        name: 'actor',
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?UserModel $actor = null;

    /**
     * The representative who acted. Mutually exclusive with {@see $actor}.
     */
    #[ManyToOne(targetEntity: CompanyUserModel::class)]
    #[JoinColumn(
        name: 'actorCompanyUser',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?CompanyUserModel $actorCompanyUser = null;

    #[Column(type: Types::ENUM)]
    public CompanyAuditVerbs $verb;

    /**
     * What the action applied to, e.g. the address invited or the type of package. Empty for verbs that need nothing
     * beyond themselves.
     */
    #[Column(type: Types::STRING)]
    public string $detail = '';

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * A human-readable name for whoever acted, or null when nobody did.
     */
    public function getActorDisplayName(): ?string
    {
        return $this->actor?->getDisplayName()
            ?? $this->actorCompanyUser?->getDisplayName();
    }

    #[PrePersist]
    public function assertSingleActor(): void
    {
        if (
            null === $this->actor
            || null === $this->actorCompanyUser
        ) {
            return;
        }

        throw new LogicException('An audit entry cannot be attributed to both a member and a company user.');
    }
}
