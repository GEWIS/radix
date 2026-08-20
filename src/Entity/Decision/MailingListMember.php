<?php

declare(strict_types=1);

namespace App\Entity\Decision;

use App\Doctrine\Query\Queryable;
use App\Entity\Decision\MailingList as MailingListModel;
use App\Repository\Decision\MailingListMemberRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * Mailing List Member model (partial)
 *
 * To allow having additional properties in the many-to-many association between {@see MailingList}s and {@see Member}s
 * we use this class as a connector.
 *
 * A subscription is identified by the list and the address that is subscribed to it, as that is the pair the ledger
 * records a change for; the member is carried along but is not part of the identifier.
 *
 * A subscription the ledger has only marked for removal is not projected, so everything here is a subscription that
 * actually stands.
 *
 * @phpstan-import-type MailingListGdprArrayType from MailingListModel as ImportedMailingListGdprArrayType
 * @phpstan-type MailingListMemberGdprArrayType = array{
 *     list: ImportedMailingListGdprArrayType,
 *     email: string,
 * }
 */
#[Entity(repositoryClass: MailingListMemberRepository::class)]
#[Queryable]
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
    private MailingList $mailingList;

    /**
     * Member. A subscription is nobody's once the member is gone, and unsubscribing them is already the first thing
     * that happens when a member is removed; the cascade only guarantees that a subscription the projection still
     * holds cannot stop the member from being removed.
     */
    #[ManyToOne(
        targetEntity: Member::class,
        inversedBy: 'mailingListMemberships',
    )]
    #[JoinColumn(
        name: 'member',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Member $member;

    /**
     * Email address on the list
     */
    #[Id]
    #[Column(type: Types::STRING)]
    private string $email;

    public function __construct()
    {
    }

    /**
     * Get the mailing list.
     */
    public function getMailingList(): MailingList
    {
        return $this->mailingList;
    }

    /**
     * Set the mailing list.
     */
    public function setMailingList(MailingList $mailingList): void
    {
        $this->mailingList = $mailingList;
    }

    /**
     * Get the member.
     */
    public function getMember(): Member
    {
        return $this->member;
    }

    /**
     * Set the member.
     */
    public function setMember(Member $member): void
    {
        $this->member = $member;
    }

    /**
     * Get the email address of this subscription
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Set the email address of this subscription
     */
    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    /**
     * @return MailingListMemberGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'list' => $this->mailingList->toGdprArray(),
            'email' => $this->getEmail(),
        ];
    }
}
