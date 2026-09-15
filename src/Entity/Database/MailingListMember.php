<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\MailingListMemberRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use LogicException;

/**
 * Mailing List Member model.
 *
 * To allow having additional properties in the many-to-many association between {@see MailingList}s and {@see Member}s
 * we use this class as a connector.
 *
 * Mailing list <-> member associations are never directly propagated to Mailman. When synchronizing the state directly,
 * the chances of something going wrong are too high. For example, we do not want someone to be registered in Mailman
 * for a list, but this is not directly visible in the database. What we do want is to always know in the database the
 * state of a member who is a member of a mailing list, as such persisting this entity is our highest priority.
 *
 * The actual synchronization should take place through cron jobs. To keep track of what is supposed to happen, the
 * additional properties in this entity are used for this.
 */
#[Entity(repositoryClass: MailingListMemberRepository::class)]
#[UniqueConstraint(
    name: 'mailinglistmember_unique_idx',
    columns: [
        'mailingList',
        'member',
        'email',
    ],
)]
class MailingListMember
{
    /**
     * Mailing list.
     */
    #[Id]
    #[ManyToOne(
        targetEntity: MailingList::class,
        inversedBy: 'mailingListMemberships',
    )]
    #[JoinColumn(
        name: 'mailingList',
        referencedColumnName: 'name',
    )]
    public MailingList $mailingList;

    /**
     * Member.
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'mailingListMemberships',
    )]
    #[JoinColumn(
        name: 'member',
        referencedColumnName: 'lidnr',
        nullable: true,
    )]
    public private(set) ?Member $member = null;

    /**
     * In case of email address changes, we need to know the email address that is on the list
     *
     * For the old email address, we have an entry toBeDeleted=True, for the new address, we have a toBeCreated=True
     */
    #[Id]
    #[Column(
        type: 'string',
        nullable: false,
    )]
    public string $email;

    /**
     * When this association was last synced to/from Mailman.
     */
    #[Column(
        type: 'datetime_immutable',
        nullable: true,
    )]
    public private(set) ?DateTimeImmutable $lastSyncOn = null;

    /**
     * Whether the last attempted sync was successful.
     *
     * At creation of the association, no sync has taken place (i.e. {@see MailingListMember::$lastSyncOn} is `null`) so
     * we default to `false`.
     */
    #[Column(type: 'boolean')]
    public bool $lastSyncSuccess = false;

    /**
     * Whether this entry still needs to be created in Mailman.
     *
     * It indicates that a new registration on a mailing list should be performed
     */
    #[Column(type: 'boolean')]
    public bool $toBeCreated = true;

    /**
     * Whether this entry still needs to be removed from Mailman.
     *
     * It indicates that there is no longer an association between the mailing list and the member.
     */
    #[Column(type: 'boolean')]
    public bool $toBeDeleted = false;

    public function __construct()
    {
    }

    /**
     * Set the member.
     * By default, this also sets the email address, but can be overriden with setEmail()
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
        // A membership is entered against an address, so a member without one has nothing to be entered on a list
        // with. Assigning it was already a type error; saying so names what went wrong.
        $this->email = $member->email ?? throw new LogicException('member without an e-mail address');
    }

    /**
     * Unset the member.
     * This is used to remove the reference to a member when the mailing list membership has to be deleted.
     */
    public function unsetMember(): void
    {
        if (!$this->toBeDeleted) {
            throw new LogicException(
                'MailingListMember member can only be unset when the mailing list membership is marked to be deleted.',
            );
        }

        $this->member = null;
    }

    /**
     * Set when the last sync happened.
     */
    public function setLastSyncOn(DateTimeImmutable $lastSyncOn = new DateTimeImmutable()): void
    {
        $this->lastSyncOn = $lastSyncOn;
    }
}
