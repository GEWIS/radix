<?php

declare(strict_types=1);

namespace App\Entity\Frontpage;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Application\Traits\TimestampableTrait;
use App\Entity\Decision\Member as MemberModel;
use App\Entity\Frontpage\Enums\PollCommentReactionType;
use App\Repository\Frontpage\PollCommentReactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * One member's response to a poll comment. Who reacted is kept only so the member can remove it or change it, and to
 * limit them to one reaction per comment; the website shows nothing but the counts.
 *
 * The member is dropped when the poll's votes are anonymised, which leaves the count intact and the reaction anonymous.
 */
#[Entity(repositoryClass: PollCommentReactionRepository::class)]
#[HasLifecycleCallbacks]
#[UniqueConstraint(
    name: 'poll_comment_reaction_uniq',
    columns: [
        'comment_id',
        'member_lidnr',
    ],
)]
class PollCommentReaction
{
    use IdentifiableTrait;
    use TimestampableTrait;

    #[ManyToOne(
        targetEntity: PollComment::class,
        inversedBy: 'reactions',
    )]
    #[JoinColumn(
        name: 'comment_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public PollComment $comment;

    /**
     * The member who reacted, or null once the poll has been anonymised.
     */
    #[ManyToOne(targetEntity: MemberModel::class)]
    #[JoinColumn(
        name: 'member_lidnr',
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'SET NULL',
    )]
    public ?MemberModel $member = null;

    #[Column(
        type: Types::STRING,
        enumType: PollCommentReactionType::class,
    )]
    public PollCommentReactionType $type;
}
