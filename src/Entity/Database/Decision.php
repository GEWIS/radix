<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\SubDecision\Annulment;
use App\Repository\Database\DecisionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function preg_replace_callback;
use function sprintf;

/**
 * Decision model.
 */
#[Entity(repositoryClass: DecisionRepository::class)]
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
    // Length spelled out: ORM 3 only copies an explicit length onto the join columns that reference this one,
    // which would otherwise become unbounded VARCHAR.
    #[Column(
        length: 255,
        enumType: MeetingTypes::class,
    )]
    private MeetingTypes $meeting_type;

    /**
     * Meeting number.
     *
     * NOTE: This is a hack to make the meeting a primary key here.
     */
    #[Id]
    #[Column(type: 'integer')]
    private int $meeting_number;

    /**
     * Point in the meeting in which the decision was made.
     */
    #[Id]
    #[Column(type: 'integer')]
    private int $point;

    /**
     * Decision number.
     */
    #[Id]
    #[Column(type: 'integer')]
    private int $number;

    /**
     * Subdecisions.
     *
     * @var Collection<array-key, SubDecision> $subdecisions
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
        // {@see SubDecision::setDecision()} adds the subdecision here as well, so callers doing both would otherwise
        // end up with it twice, and with the content of the decision repeating itself.
        if ($this->subdecisions->contains($subdecision)) {
            return;
        }

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
     * Or null, if it wasn't annulled.
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

    /**
     * Get the string ("hash") that uniquely identifies this decision.
     *
     * Referencing a decision should always happen through this and only this identifier (or a variation thereof). No
     * alternative version is provided (in contrast to the contents of this decision).
     */
    public function getHash(): string
    {
        return sprintf(
            '%s %d.%d.%d',
            $this->getMeetingType()->value,
            $this->getMeetingNumber(),
            $this->getPoint(),
            $this->getNumber(),
        );
    }

    /**
     * Escape special LaTeX characters.
     *
     * The ordering of the replacements is of utmost importance to prevent creating illegal LaTeX commands or mangling
     * the intended output. As such, we cannot use {@see \str_replace()} which will replace earlier replacements and
     * have to use a regex to actually achieve this.
     */
    private function escapeLaTeXCharacters(string $content): string
    {
        $replacements = [
            '&' => '\\&',
            '%' => '\\%',
            '$' => '\\$',
            '#' => '\\#',
            '_' => '\\_',
            '[' => '\\[',
            ']' => '\\]',
            '{' => '\\{',
            '}' => '\\}',
            '~' => '\\textasciitilde{}',
            '^' => '\\textasciicircum{}',
            '\\' => '\\textbackslash{}',
            '<' => '\\textless{}',
            '>' => '\\textgreater{}',
        ];

        $return = preg_replace_callback(
            '/([&%$#_\[\]{}~^\\\\<>])/',
            static function ($matches) use ($replacements) {
                return $replacements[$matches[0]];
            },
            $content,
        );

        if (null === $return) {
            // preg_replace can only return null on error, so this should never happen.
            throw new RuntimeException('An error occurred while escaping LaTeX characters');
        }

        return $return;
    }

    /**
     * Get the statutory content of the decision by going over all subdecisions.
     */
    public function getContent(
        TranslatorInterface $translator,
        bool $escapeCharacters = false,
    ): string {
        return $this->getTranslatedContent(
            $translator,
            AppLanguages::Dutch,
            $escapeCharacters,
        );
    }

    /**
     * Get the content of the decision in a specified language by going over all subdecisions.
     */
    public function getTranslatedContent(
        TranslatorInterface $translator,
        AppLanguages $language,
        bool $escapeCharacters = false,
    ): string {
        $content = [];
        foreach ($this->getSubdecisions() as $subdecision) {
            $content[] = $subdecision->getTranslatedContent(
                $translator,
                $language,
            );
        }

        $contents = implode(
            ' ',
            $content,
        );

        return $escapeCharacters
            ? $this->escapeLaTeXCharacters($contents)
            : $contents;
    }

    /**
     * Transform into an array.
     *
     * @return array{
     *     meeting_type: MeetingTypes,
     *     meeting_number: int,
     *     decision_point: int,
     *     decision_number: int,
     *     content: string,
     * }
     */
    public function toArray(TranslatorInterface $translator): array
    {
        $content = $this->getContent($translator);

        return [
            'meeting_type' => $this->getMeeting()->getType(),
            'meeting_number' => $this->getMeeting()->getNumber(),
            'decision_point' => $this->getPoint(),
            'decision_number' => $this->getNumber(),
            'content' => $content,
        ];
    }
}
