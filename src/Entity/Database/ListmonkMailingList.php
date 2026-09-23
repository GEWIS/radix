<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\ListmonkMailingListRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Model that represents/caches listmonk mailing lists and stores some additional information
 */
#[Entity(repositoryClass: ListmonkMailingListRepository::class)]
class ListmonkMailingList
{
    /**
     * Listmonk-identifier
     */
    #[Id]
    #[Column(
        name: 'id',
        type: Types::INTEGER,
    )]
    public int $listmonkId;

    /**
     * Name of this list in the listmonk side
     */
    #[Column(type: Types::STRING)]
    public string $name;

    /**
     * When this list was last observed in listmonk
     */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) DateTimeImmutable $lastSeen;

    /**
     * When the last full check of this mailing list took place
     */
    #[Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true,
    )]
    public private(set) ?DateTimeImmutable $lastCheck = null;

    /**
     * The corresponding mailing list in the register
     * If null, this list is not managed by the register
     */
    #[OneToOne(
        targetEntity: MailingList::class,
        mappedBy: 'listmonkList',
    )]
    public private(set) ?MailingList $mailingList = null;

    /**
     * Set the date the list was last seen
     * It is only sensible if this happens during a sync
     */
    public function setLastSeen(DateTimeImmutable $lastSeen = new DateTimeImmutable()): void
    {
        $this->lastSeen = $lastSeen;
    }

    /**
     * Set the date the list was last fully checked
     */
    public function setLastCheck(DateTimeImmutable $lastCheck = new DateTimeImmutable()): void
    {
        $this->lastCheck = $lastCheck;
    }

    /**
     * Whether this listmonk list is managed by the register
     */
    public function isManaged(): bool
    {
        return null !== $this->mailingList;
    }
}
