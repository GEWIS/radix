<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\MailmanMailingListRepository;
use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Model that represents/caches mailman mailing lists and stores some additional information
 */
#[Entity(repositoryClass: MailmanMailingListRepository::class)]
class MailmanMailingList
{
    /**
     * Mailman-identifier.
     */
    #[Id]
    // Length spelled out: ORM 3 only copies an explicit length onto the join columns that reference this one,
    // which would otherwise become unbounded VARCHAR.
    #[Column(
        name: 'id',
        type: 'string',
        length: 255,
    )]
    public string $mailmanId;

    /**
     * Name of this list in the mailman side
     */
    #[Column(type: 'string')]
    public string $name;

    /**
     * When this list was last observed in mailman
     */
    #[Column(type: 'datetime')]
    public private(set) DateTime $lastSeen;

    /**
     * When the last full check of this mailing list took place
     */
    #[Column(
        type: 'datetime',
        nullable: true,
    )]
    public private(set) ?DateTime $lastCheck = null;

    /**
     * The corresponding mailing list in the register
     * If null, this list is not managed by the register
     */
    #[OneToOne(
        targetEntity: MailingList::class,
        mappedBy: 'mailmanList',
    )]
    public private(set) ?MailingList $mailingList = null;

    /**
     * Set the date the list was last seen
     * It is only sensible if this happens during a sync
     */
    public function setLastSeen(DateTime $lastSeen = new DateTime()): void
    {
        $this->lastSeen = $lastSeen;
    }

    /**
     * Set the date the list was last fully checked
     */
    public function setLastCheck(DateTime $lastCheck = new DateTime()): void
    {
        $this->lastCheck = $lastCheck;
    }

    /**
     * Whether this mailman list is managed by the register
     */
    public function isManaged(): bool
    {
        return null !== $this->mailingList;
    }
}
