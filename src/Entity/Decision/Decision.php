<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\SubDecision\Annulment;
use App\Repository\Decision\DecisionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;

/**
 * Decision model.
 */
#[Entity(repositoryClass: DecisionRepository::class)]
#[Queryable]
class Decision
{
    /**
     * Meeting.
     */
    #[ManyToOne(
        targetEntity: Meeting::class,
        inversedBy: 'decisions',
    )]
    #[JoinColumn(
        name: 'meeting_type',
        referencedColumnName: 'type',
        nullable: false,
    )]
    #[JoinColumn(
        name: 'meeting_number',
        referencedColumnName: 'number',
        nullable: false,
    )]
    private Meeting $meeting;

    /**
     * Meeting type.
     *
     * NOTE: This is a hack to make the meeting a primary key here.
     */
    #[Id]
    #[Column(type: Types::ENUM)]
    private MeetingTypes $meeting_type;

    /**
     * Meeting number.
     *
     * NOTE: This is a hack to make the meeting a primary key here.
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    private int $meeting_number;

    /**
     * Point in the meeting in which the decision was made.
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    private int $point;

    /**
     * Decision number.
     */
    #[Id]
    #[Column(type: Types::INTEGER)]
    private int $number;

    /**
     * Content in Dutch.
     *
     * Generated from subdecisions.
     */
    #[Column(type: Types::TEXT)]
    private string $contentNL;

    /**
     * Content in English.
     *
     * Generated from subdecisions.
     */
    #[Column(type: Types::TEXT)]
    private string $contentEN;

    /**
     * Subdecisions.
     *
     * @var Collection<array-key, SubDecision>
     */
    #[OneToMany(
        targetEntity: SubDecision::class,
        mappedBy: 'decision',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    #[OrderBy(value: ['sequence' => 'ASC'])]
    private Collection $subdecisions;

    /**
     * The decision this one is the counterpart of.
     *
     * A virtual meeting exists to put on the record something a real meeting decided, most often an organ membership
     * that started or ended on a date of its own rather than on the day it was decided. Naming that decision here is
     * what keeps the two from reading as two separate decisions: the register can show what the virtual one belongs
     * to, and the search shows the decision it belongs to rather than both.
     *
     * Said from the meeting rather than while the decision is entered, so that neither of the two has to be on the
     * record before the other. Null on every decision that has no such counterpart, which is all of them outside a
     * virtual meeting.
     */
    #[ManyToOne(
        targetEntity: self::class,
        inversedBy: 'virtualCounterparts',
    )]
    #[JoinColumn(
        name: 'c_meeting_type',
        referencedColumnName: 'meeting_type',
    )]
    #[JoinColumn(
        name: 'c_meeting_number',
        referencedColumnName: 'meeting_number',
    )]
    #[JoinColumn(
        name: 'c_point',
        referencedColumnName: 'point',
    )]
    #[JoinColumn(
        name: 'c_number',
        referencedColumnName: 'number',
    )]
    private ?Decision $counterpart = null;

    /**
     * The virtual decisions that are this one's counterpart.
     *
     * The reference itself sits on the virtual decision, because one decision can be given more than one -- an
     * installation decided once and recorded twice, for two dates of its own. Saying which virtual decision belongs
     * to this one is done from here, which is where a reader comes across it.
     *
     * @var Collection<array-key, Decision>
     */
    #[OneToMany(
        targetEntity: self::class,
        mappedBy: 'counterpart',
    )]
    private Collection $virtualCounterparts;

    /**
     * Annulled by.
     */
    #[OneToOne(
        targetEntity: Annulment::class,
        mappedBy: 'target',
    )]
    private ?Annulment $annulledBy = null;

    /**
     * A decision that was just made has no counterpart either way; Doctrine fills both in for one that was loaded.
     * Only `virtualCounterparts` is initialised here, because {@see self::setMeeting()} owns the other collection and
     * deliberately empties it.
     */
    public function __construct()
    {
        $this->virtualCounterparts = new ArrayCollection();
    }

    /**
     * Set the meeting.
     */
    public function setMeeting(Meeting $meeting): void
    {
        $this->subdecisions = new ArrayCollection();

        $meeting->addDecision($this);
        $this->meeting_type = $meeting->getType();
        $this->meeting_number = $meeting->getNumber();
        $this->meeting = $meeting;
    }

    /**
     * Get the meeting type.
     */
    public function getMeetingType(): MeetingTypes
    {
        return $this->meeting_type;
    }

    /**
     * Get the meeting number.
     */
    public function getMeetingNumber(): int
    {
        return $this->meeting_number;
    }

    /**
     * Get the meeting.
     */
    public function getMeeting(): Meeting
    {
        return $this->meeting;
    }

    /**
     * Set the point number.
     */
    public function setPoint(int $point): void
    {
        $this->point = $point;
    }

    /**
     * Get the point number.
     */
    public function getPoint(): int
    {
        return $this->point;
    }

    /**
     * Set the decision number.
     */
    public function setNumber(int $number): void
    {
        $this->number = $number;
    }

    /**
     * Get the decision number.
     */
    public function getNumber(): int
    {
        return $this->number;
    }

    /**
     * Get decision content in Dutch.
     */
    public function getContentNL(): string
    {
        return $this->contentNL;
    }

    /**
     * Set decision content in Dutch.
     */
    public function setContentNL(string $content): void
    {
        $this->contentNL = $content;
    }

    /**
     * The decision text for a locale. English content has only been recorded for recent decisions, so older ones
     * fall back to the Dutch text.
     */
    public function getLocalisedContent(string $locale): string
    {
        if (
            'en' === $locale
            && '' !== $this->contentEN
        ) {
            return $this->contentEN;
        }

        return $this->contentNL;
    }

    /**
     * Get decision content in English.
     */
    public function getContentEN(): string
    {
        return $this->contentEN;
    }

    /**
     * Set decision content in English.
     */
    public function setContentEN(string $content): void
    {
        $this->contentEN = $content;
    }

    /**
     * Get the subdecisions.
     *
     * @return Collection<array-key, SubDecision>
     */
    public function getSubdecisions(): Collection
    {
        return $this->subdecisions;
    }

    /**
     * Add a subdecision.
     */
    public function addSubdecision(SubDecision $subdecision): void
    {
        $this->subdecisions[] = $subdecision;
    }

    /**
     * Add multiple subdecisions.
     *
     * @param SubDecision[] $subdecisions
     */
    public function addSubdecisions(array $subdecisions): void
    {
        foreach ($subdecisions as $subdecision) {
            $this->addSubdecision($subdecision);
        }
    }

    /**
     * Get the virtual decisions that are this one's counterpart.
     *
     * @return Collection<array-key, Decision>
     */
    public function getVirtualCounterparts(): Collection
    {
        return $this->virtualCounterparts;
    }

    /**
     * Get the decision this one is the counterpart of, if it is one.
     */
    public function getCounterpart(): ?Decision
    {
        return $this->counterpart;
    }

    /**
     * Set the decision this one is the counterpart of.
     */
    public function setCounterpart(?Decision $counterpart): void
    {
        $this->counterpart = $counterpart;
    }

    /**
     * Get the subdecision by which this decision is annulled.
     *
     * Or null, if it was not annulled.
     */
    public function getAnnulledBy(): ?Annulment
    {
        return $this->annulledBy;
    }

    /**
     * Check if this decision is annulled by another decision.
     */
    public function isAnnulled(): bool
    {
        return null !== $this->annulledBy;
    }
}
