<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\User\User as UserModel;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * One entry in the audit trail of in-place edits to an {@see ActivityRevision} draft: who saved it, when, and which of
 * its fields they changed. Appended automatically on every member-driven save by
 * {@see \App\EventListener\Activity\RevisionAuditListener}, so any change can be attributed to the member who made it.
 */
#[Entity]
class ActivityRevisionEdit
{
    use IdentifiableTrait;

    /**
     * The revision this edit was made to.
     */
    #[ManyToOne(
        targetEntity: ActivityRevision::class,
        inversedBy: 'editHistory',
    )]
    #[JoinColumn(nullable: false)]
    public ActivityRevision $revision;

    /**
     * The user (a member's account) who made the edit; activities are only ever edited by members. Null once that
     * account is removed: the trail belongs to the revision, which the members who own the activity still rely on, so
     * the entry keeps when it happened and which fields it changed, and only the name is lost.
     */
    #[ManyToOne(targetEntity: UserModel::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?UserModel $editor = null;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $editedAt;

    /**
     * The names of the revision fields that changed in this save (e.g. ['organ', 'name', 'beginTime']).
     *
     * @var string[]
     */
    #[Column(type: Types::JSON)]
    public array $changedFields = [];

    public function getEditor(): ?UserModel
    {
        return $this->editor;
    }

    /**
     * An entry is only ever appended for an account that is there to be named, so the application never writes an
     * anonymous one; the column is emptied by the database alone, when the account behind it is removed.
     */
    public function setEditor(UserModel $editor): void
    {
        $this->editor = $editor;
    }

    /**
     * A human-readable name for the member who made this edit, or null once their account is removed and the entry
     * names no member.
     */
    public function getEditorDisplayName(): ?string
    {
        return $this->editor?->member->getFullName();
    }
}
