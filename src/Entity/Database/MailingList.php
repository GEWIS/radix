<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\MailingListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Mailing List model for lists on the db side.
 */
#[Entity(repositoryClass: MailingListRepository::class)]
class MailingList
{
    /**
     * Name of the mailing list
     */
    #[Id]
    // Length spelled out: ORM 3 only copies an explicit length onto the join columns that reference this one,
    // which would otherwise become unbounded VARCHAR.
    #[Column(
        type: Types::STRING,
        length: 255,
    )]
    public string $name;

    /**
     * Dutch description of the mailing list.
     */
    #[Column(type: Types::TEXT)]
    private string $nl_description;

    /**
     * English description of the mailing list.
     */
    #[Column(type: Types::TEXT)]
    private string $en_description;

    /**
     * If the mailing list should be on the form.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $onForm;

    /**
     * If members should be subscribed by default.
     *
     * (when it is on the form, that means that the checkbox is checked by default)
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $defaultSub;

    /**
     * Whether a member may manage their own subscription. Separate from being on the sign-up form: a list for one
     * year is offered when somebody joins, but is not one they may put themselves on later.
     */
    #[Column(
        type: 'boolean',
        options: ['default' => false],
    )]
    public bool $selfService = false;

    /**
     * The corresponding mailman mailing list
     */
    #[OneToOne(
        targetEntity: MailmanMailingList::class,
        inversedBy: 'mailingList',
    )]
    #[JoinColumn(
        name: 'mailmanId',
        referencedColumnName: 'id',
    )]
    public ?MailmanMailingList $mailmanList = null;

    /**
     * The corresponding listmonk mailing list
     */
    #[OneToOne(
        targetEntity: ListmonkMailingList::class,
        inversedBy: 'mailingList',
    )]
    #[JoinColumn(
        name: 'listmonkId',
        referencedColumnName: 'id',
    )]
    public ?ListmonkMailingList $listmonkList = null;

    /**
     * Mailing list members.
     *
     * @var Collection<array-key, MailingListMember>
     */
    #[OneToMany(
        targetEntity: MailingListMember::class,
        mappedBy: 'mailingList',
    )]
    private Collection $mailingListMemberships;

    /**
     * Audit entries for the mailing list.
     *
     * @var Collection<array-key, AuditMailingListMembership>
     */
    #[OneToMany(
        targetEntity: AuditMailingListMembership::class,
        mappedBy: 'mailingList',
    )]
    private Collection $auditEntries;

    public function __construct()
    {
        $this->mailingListMemberships = new ArrayCollection();
        $this->auditEntries = new ArrayCollection();
    }

    /**
     * Get the english description.
     */
    public function getEnDescription(): string
    {
        return $this->en_description;
    }

    /**
     * Set the english description.
     */
    public function setEnDescription(string $description): void
    {
        $this->en_description = $description;
    }

    /**
     * Get the dutch description.
     */
    public function getNlDescription(): string
    {
        return $this->nl_description;
    }

    /**
     * Set the dutch description.
     */
    public function setNlDescription(string $description): void
    {
        $this->nl_description = $description;
    }

    /**
     * Check if this has a mailman mailing list
     */
    public function isOnMailman(): bool
    {
        return null !== $this->mailmanList;
    }

    /**
     * Check if this has a listmonk mailing list
     */
    public function isOnListmonk(): bool
    {
        return null !== $this->listmonkList;
    }

    /**
     * Get subscribed members.
     *
     * @return Collection<array-key, MailingListMember>
     */
    public function getMailingListMemberships(): Collection
    {
        return $this->mailingListMemberships;
    }

    /**
     * Get the audit entries for this mailing list.
     *
     * @return Collection<array-key, AuditMailingListMembership>
     */
    public function getAuditEntries(): Collection
    {
        return $this->auditEntries;
    }

    /**
     * @return array{
     *     name: string,
     *     nl_description: string,
     *     en_description: string,
     *     defaultSub: bool,
     *     onForm: bool,
     *     selfService: bool,
     *     mailmanList: ?string,
     *     listmonkList: ?int,
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'nl_description' => $this->getNlDescription(),
            'en_description' => $this->getEnDescription(),
            'defaultSub' => $this->defaultSub,
            'onForm' => $this->onForm,
            'selfService' => $this->selfService,
            'mailmanList' => $this->mailmanList?->mailmanId,
            'listmonkList' => $this->listmonkList?->listmonkId,
        ];
    }
}
