<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Application\Traits\TimestampableTrait;
use App\Repository\Database\AuditEntryRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use LogicException;
use UnexpectedValueException;

use function strip_tags;

/**
 * Abstract audit log entry, can take different types
 */
#[Entity(repositoryClass: AuditEntryRepository::class)]
#[HasLifecycleCallbacks]
#[InheritanceType('SINGLE_TABLE')]
#[DiscriminatorColumn(
    name: 'type',
    type: 'string',
)]
#[DiscriminatorMap(
    value: [
        'mailing_list_membership' => AuditMailingListMembership::class,
        'note' => AuditNote::class,
        'renewal' => AuditRenewal::class,
    ],
)]
abstract class AuditEntry
{
    use TimestampableTrait;

    /**
     * Whether this entry type can be removed/changed
     * While this one can technically be private, all child classes need to have this 'protected'
     * to allow isDeletable to work, so we make it protected here to enforce this
     */
    protected const bool IMMUTABLE = true;

    /**
     * Entry ID.
     */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * The member who made the entry.
     *
     * A member rather than an account: the register is administered by whoever holds the office, and an entry says
     * who did something rather than which login they did it under. Emptied rather than removed when that member is,
     * because what was done still happened.
     */
    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'member_lidnr',
        referencedColumnName: 'lidnr',
        onDelete: 'set null',
        nullable: true,
    )]
    protected ?Member $user = null;

    /**
     * If this entry is linked to a member, the member who this entry is linked to
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'auditEntries',
    )]
    #[JoinColumn(
        name: 'member',
        referencedColumnName: 'lidnr',
        onDelete: 'cascade',
        nullable: true,
    )]
    private ?Member $member = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getUser(): ?Member
    {
        return $this->user;
    }

    public function setUser(?Member $user): void
    {
        $this->user = $user;
    }

    /**
     * The member number of whoever made the entry, or null once that member is gone.
     *
     * A number rather than a name: it is what the register is searched and referred to by, it does not move when
     * someone's name does, and it still says who acted when the member it belongs to has been deleted.
     */
    public function getUserLidnr(): ?int
    {
        return $this->user?->getLidnr();
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function setMember(Member $member): void
    {
        if (
            null !== $this->member
            && $this->member !== $member
        ) {
            throw new LogicException('Must not link an audit entry to another object after creation');
        }

        $this->member = $member;
    }

    /**
     * It is not possible to require a link always when constructing the model, but we want
     * to always link at least one of the linkable objects. Currently only member is possible
     */
    public function assertValid(): void
    {
        if (null === $this->member) {
            throw new UnexpectedValueException(
                'Asserting that object of type ' .
                $this::class .
                ' is linked to at least one object.',
            );
        }
    }

    /**
     * Get a textual representation of this audit entry
     * The first element is to be the body which after translation can be
     * supplied as an argument to sprintf
     *
     * @return array{bodyPlain: string, bodyFormatted: string, arguments: array<string>}
     */
    final public function getStringPlain(): array
    {
        return [
            'bodyPlain' => $this->getStringBodyPlain(),
            'bodyFormatted' => $this->getStringBodyFormatted(),
            'arguments' => $this->getStringArguments(),
        ];
    }

    /**
     * Whether this entry type can be edited
     * Not implemented
     */
    final public function isEditable(): bool
    {
        return false;
    }

    /**
     * Whether this entry type can be removed
     */
    final public function isDeletable(): bool
    {
        // phpcs:ignore SlevomatCodingStandard.Classes.DisallowLateStaticBindingForConstants.DisallowedLateStaticBindingForConstant -- intentionally overridable
        return !static::IMMUTABLE;
    }

    /**
     * Get the string body, currently is constant for all types, but may change
     */
    private function getStringBodyPlain(): string
    {
        return strip_tags($this->getStringBodyFormatted());
    }

    abstract protected function getStringBodyFormatted(): string;

    /**
     * @return array<string>
     */
    abstract protected function getStringArguments(): array;
}
