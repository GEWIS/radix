<?php

declare(strict_types=1);

namespace App\Entity\Frontpage;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Decision\Member as MemberModel;
use App\Entity\Frontpage\Enums\PollCommentReactionType;
use App\Repository\Frontpage\PollCommentRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use SortDirection;

/**
 * What a member wrote underneath a poll. Members sign their comment with a name of their own choosing, and only the
 * board gets to see which member that was.
 *
 * A comment either stands on its own or is a reply to another, however deep that goes.
 *
 * @phpstan-type PollCommentGdprArrayType = array{
 *     id: ?int,
 *     createdOn: string,
 *     author: string,
 *     content: string,
 * }
 */
#[Entity(repositoryClass: PollCommentRepository::class)]
class PollComment
{
    use IdentifiableTrait;

    /**
     * Referenced poll.
     */
    #[ManyToOne(
        targetEntity: Poll::class,
        inversedBy: 'comments',
    )]
    #[JoinColumn(
        name: 'poll_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public Poll $poll;

    #[ManyToOne(
        targetEntity: self::class,
        inversedBy: 'replies',
    )]
    #[JoinColumn(
        name: 'parent_id',
        referencedColumnName: 'id',
        nullable: true,
    )]
    public private(set) ?PollComment $parent = null;

    /** @var Collection<array-key, PollComment> */
    #[OneToMany(
        targetEntity: self::class,
        mappedBy: 'parent',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    #[OrderBy(['createdOn' => SortDirection::Ascending])]
    private Collection $replies;

    /** @var Collection<array-key, PollCommentReaction> */
    #[OneToMany(
        targetEntity: PollCommentReaction::class,
        mappedBy: 'comment',
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    private Collection $reactions;

    /**
     * The member who posted the comment, or null once that member has been removed from the register. The comment
     * itself is signed with {@see $author}, a name of the member's own choosing, and the thread other members are
     * reading is built out of that and {@see $content}; only the board's ability to see who was behind it is lost.
     */
    #[ManyToOne(targetEntity: MemberModel::class)]
    #[JoinColumn(
        name: 'user_lidnr',
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    private ?MemberModel $user = null;

    /**
     * Author of the comment.
     */
    #[Column(type: Types::STRING)]
    public string $author;

    /**
     * Comment content.
     */
    #[Column(type: Types::TEXT)]
    public string $content;

    /**
     * Comment date.
     */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $createdOn;

    public function __construct()
    {
        $this->replies = new ArrayCollection();
        $this->reactions = new ArrayCollection();
    }

    public function setParent(?PollComment $parent): void
    {
        $this->parent = $parent;
        $parent?->addReply($this);
    }

    /**
     * @return Collection<array-key, PollComment>
     */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    /**
     * Kept in step with {@see self::setParent()} so a reply appears under the comment it replies to straight away,
     * rather than only after the thread is read from the database again.
     */
    public function addReply(PollComment $reply): void
    {
        if ($this->replies->contains($reply)) {
            return;
        }

        $this->replies->add($reply);
    }

    /**
     * @return Collection<array-key, PollCommentReaction>
     */
    public function getReactions(): Collection
    {
        return $this->reactions;
    }

    public function addReaction(PollCommentReaction $reaction): void
    {
        if ($this->reactions->contains($reaction)) {
            return;
        }

        $this->reactions->add($reaction);
        $reaction->comment = $this;
    }

    public function removeReaction(PollCommentReaction $reaction): void
    {
        $this->reactions->removeElement($reaction);
    }

    /**
     * @return array<string, int>
     */
    public function getReactionCounts(): array
    {
        $counts = [];

        foreach ($this->reactions as $reaction) {
            $type = $reaction->type->value;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    public function getReactionOf(MemberModel $member): ?PollCommentReactionType
    {
        foreach ($this->reactions as $reaction) {
            if ($reaction->member?->lidnr !== $member->lidnr) {
                continue;
            }

            return $reaction->type;
        }

        return null;
    }

    /**
     * Get the user.
     */
    public function getUser(): ?MemberModel
    {
        return $this->user;
    }

    /**
     * Set the user.
     */
    public function setUser(MemberModel $user): void
    {
        $this->user = $user;
    }

    /**
     * @return PollCommentGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'id' => $this->id,
            'createdOn' => $this->createdOn->format(DateTimeInterface::ATOM),
            'author' => $this->author,
            'content' => $this->content,
        ];
    }
}
