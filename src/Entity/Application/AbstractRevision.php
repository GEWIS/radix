<?php

declare(strict_types=1);

namespace App\Entity\Application;

use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\Decision\Member as MemberModel;
use App\Entity\User\CompanyUser as CompanyUserModel;
use App\Entity\User\User as UserModel;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\PreUpdate;
use Doctrine\ORM\Mapping\Version;
use LogicException;
use Override;

/**
 * Shared base for every revision entity. Declares the workflow fields common to all revisable domains; the concrete
 * subclasses add the domain-specific content snapshot, the typed back-reference to their aggregate, and the
 * self-referencing `previousRevision` link.
 *
 * Only unidirectional, owning-side associations to a *concrete* entity may be defined on a mapped superclass, so
 * `author` and `reviewer` (both -> {@see MemberModel}) are declared here; `previousRevision` (a self-reference) and the
 * aggregate back-reference are declared per subclass.
 *
 * Everyone a revision names is named for attribution; the text itself is on the revision and outlives the person who
 * wrote it. All six of those columns are therefore nullable with `ON DELETE SET NULL`, so removing a member, their
 * account or a company user leaves the revision (and the thing it is a revision of) in place but unsigned.
 *
 * Concrete subclasses MUST declare {@see \Doctrine\ORM\Mapping\HasLifecycleCallbacks} so the timestamp callbacks from
 * {@see TimestampableTrait} are registered.
 */
#[MappedSuperclass]
abstract class AbstractRevision implements RevisionInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[Column(
        type: Types::STRING,
        enumType: RevisionStatus::class,
    )]
    private RevisionStatus $status = RevisionStatus::Draft;

    #[Column(type: Types::INTEGER)]
    private int $revisionNumber = 1;

    /**
     * The member who authored this revision (for member-authored domains such as activities, and for revisions a
     * board/C4 member drafts on behalf of a company). Mutually exclusive with {@see $authorCompanyUser}.
     */
    #[ManyToOne(targetEntity: MemberModel::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?MemberModel $author = null;

    /**
     * The company user who authored this revision (a company drafting for its own vacancy/profile). Mutually
     * exclusive with {@see $author}.
     */
    #[ManyToOne(targetEntity: CompanyUserModel::class)]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?CompanyUserModel $authorCompanyUser = null;

    /**
     * The member who last reviewed this revision (approved/rejected/requested changes), if any.
     */
    #[ManyToOne(targetEntity: MemberModel::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?MemberModel $reviewer = null;

    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    private ?DateTimeImmutable $reviewedAt = null;

    /**
     * When this revision was submitted to its reviewers, which is a different moment from when it was written: a draft
     * can be worked on for a week before it is submitted. Null while it never has been.
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    private ?DateTimeImmutable $submittedAt = null;

    /**
     * Optimistic-locking version, bumped on every flush. A backstop against lost updates if two edits ever race past
     * the edit lock ({@see \App\Service\Application\EditLockService}): the second flush fails with an
     * OptimisticLockException, which the edit controller turns into a "changed by someone else" message.
     */
    #[Version]
    #[Column(
        type: Types::INTEGER,
        options: ['default' => 1],
    )]
    public private(set) int $version = 1;

    /**
     * The user (a member's account) who last saved an edit to this revision. Unlike {@see $author} (fixed when the
     * draft is spawned) this reflects in-place edits by a co-owner. Mutually exclusive with
     * {@see $lastEditedByCompanyUser}.
     */
    #[ManyToOne(targetEntity: UserModel::class)]
    #[JoinColumn(
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public private(set) ?UserModel $lastEditedBy = null;

    /**
     * The company user who last saved an edit (careers portal). Mutually exclusive with {@see $lastEditedBy}.
     */
    #[ManyToOne(targetEntity: CompanyUserModel::class)]
    #[JoinColumn(
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public private(set) ?CompanyUserModel $lastEditedByCompanyUser = null;

    #[Override]
    public function getStatus(): RevisionStatus
    {
        return $this->status;
    }

    #[Override]
    public function setStatus(RevisionStatus $status): void
    {
        $this->status = $status;
    }

    #[Override]
    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    #[Override]
    public function setRevisionNumber(int $revisionNumber): void
    {
        $this->revisionNumber = $revisionNumber;
    }

    #[Override]
    public function getAuthor(): ?MemberModel
    {
        return $this->author;
    }

    /**
     * Taking over authorship transfers it entirely: a board member who picks up a profile a company submitted is now
     * its author, and the company user no longer is.
     */
    #[Override]
    public function setAuthor(?MemberModel $author): void
    {
        $this->author = $author;

        if (null === $author) {
            return;
        }

        $this->authorCompanyUser = null;
    }

    #[Override]
    public function getAuthorCompanyUser(): ?CompanyUserModel
    {
        return $this->authorCompanyUser;
    }

    #[Override]
    public function setAuthorCompanyUser(?CompanyUserModel $authorCompanyUser): void
    {
        $this->authorCompanyUser = $authorCompanyUser;

        if (null === $authorCompanyUser) {
            return;
        }

        $this->author = null;
    }

    /**
     * A human-readable name for the author of this revision, regardless of whether that was a member or a company.
     */
    #[Override]
    public function getAuthorDisplayName(): string
    {
        if (null !== $this->author) {
            return $this->author->getFullName();
        }

        if (null !== $this->authorCompanyUser) {
            return $this->authorCompanyUser->getDisplayName();
        }

        return '';
    }

    #[Override]
    public function getReviewer(): ?MemberModel
    {
        return $this->reviewer;
    }

    #[Override]
    public function setReviewer(?MemberModel $reviewer): void
    {
        $this->reviewer = $reviewer;
    }

    #[Override]
    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    #[Override]
    public function setReviewedAt(?DateTimeImmutable $reviewedAt): void
    {
        $this->reviewedAt = $reviewedAt;
    }

    #[Override]
    public function getSubmittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    #[Override]
    public function setSubmittedAt(?DateTimeImmutable $submittedAt): void
    {
        $this->submittedAt = $submittedAt;
    }

    public function setLastEditedBy(?UserModel $lastEditedBy): void
    {
        $this->lastEditedBy = $lastEditedBy;

        if (null === $lastEditedBy) {
            return;
        }

        $this->lastEditedByCompanyUser = null;
    }

    public function setLastEditedByCompanyUser(?CompanyUserModel $lastEditedByCompanyUser): void
    {
        $this->lastEditedByCompanyUser = $lastEditedByCompanyUser;

        if (null === $lastEditedByCompanyUser) {
            return;
        }

        $this->lastEditedBy = null;
    }

    /**
     * A human-readable name for the last editor of this revision, or null if it has never been edited in place.
     */
    public function getLastEditorDisplayName(): ?string
    {
        return $this->lastEditedBy?->getDisplayName()
            ?? $this->lastEditedByCompanyUser?->getDisplayName();
    }

    /**
     * What visitors are seeing while this revision is not: the live revision, unless that is this one.
     *
     * A revision that was rejected, or is still with the reviewers, does not indicate what is public, so a screen
     * showing one can name the live revision instead of leaving the reader to assume the worst.
     */
    #[Override]
    public function getLiveCounterpart(): ?RevisionInterface
    {
        $live = $this->getRevisable()->getLiveRevision();

        return $live === $this
            ? null
            : $live;
    }

    /**
     * Enforce the documented invariant that a revision is never authored, nor last edited, by both a member and a
     * company user at once. The setters clear the other side rather than let both be set, so this catches only what
     * reached the fields another way, such as a row hydrated from the database.
     */
    #[PrePersist]
    #[PreUpdate]
    public function assertSingleActor(): void
    {
        if (
            null !== $this->author
            && null !== $this->authorCompanyUser
        ) {
            throw new LogicException('A revision cannot be authored by both a member and a company user.');
        }

        if (
            null !== $this->lastEditedBy
            && null !== $this->lastEditedByCompanyUser
        ) {
            throw new LogicException('A revision cannot be last edited by both a member and a company user.');
        }
    }
}
