<?php

declare(strict_types=1);

namespace App\Entity\Frontpage;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\Frontpage\PollOptionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * One of the answers a poll can be given. An option belongs to the revision the question was written in, so a question
 * that went past the board is never given options it was not approved with.
 */
#[Entity(repositoryClass: PollOptionRepository::class)]
class PollOption
{
    use IdentifiableTrait;

    #[ManyToOne(
        targetEntity: PollRevision::class,
        inversedBy: 'options',
        cascade: ['persist'],
    )]
    #[JoinColumn(
        name: 'revision_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public PollRevision $revision;

    /**
     * The localised text for this option.
     */
    #[OneToOne(
        targetEntity: FrontpageLocalisedText::class,
        cascade: [
            'persist',
            'remove',
        ],
        orphanRemoval: true,
    )]
    #[JoinColumn(
        name: 'text_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public FrontpageLocalisedText $text;

    /**
     * Votes for this option.
     *
     * @var Collection<array-key, PollVote>
     */
    #[OneToMany(
        targetEntity: PollVote::class,
        mappedBy: 'pollOption',
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $votes;

    /**
     * The votes that were cast on this option and have since been anonymised, which are counted but no longer stored
     * one row at a time.
     */
    #[Column(
        type: Types::INTEGER,
        options: ['default' => 0],
    )]
    public int $anonymousVotes = 0;

    /**
     * How many votes this option was given, counted for it by the poll repository while it primed the results. That
     * is where the number comes from on every page showing results; without it the votes are counted one by one.
     */
    private ?int $countedVotes = null;

    public function __construct()
    {
        $this->votes = new ArrayCollection();

        $this->text = new FrontpageLocalisedText(
            null,
            null,
        );
    }

    /**
     * @return Collection<array-key, PollVote>
     */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    /**
     * Get the number of votes for this poll option.
     */
    public function getVotesCount(): int
    {
        return ($this->countedVotes ?? $this->votes->count()) + $this->anonymousVotes;
    }

    public function setCountedVotes(int $countedVotes): void
    {
        $this->countedVotes = $countedVotes;
    }
}
