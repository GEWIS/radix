<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Database\Enums\MailingListMemberAction;
use App\Entity\Database\Enums\MailingListMemberOrigin;
use App\Repository\Database\AuditMailingListMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Override;

#[Entity(repositoryClass: AuditMailingListMembershipRepository::class)]
class AuditMailingListMembership extends AuditEntry
{
    private const string BODY_FORMAT = '<strong>%s mailinglist subscription</strong> for '
        . '<emph>%s</emph> on <emph>%s</emph> (%s)';

    #[Column(
        type: Types::STRING,
        enumType: MailingListMemberAction::class,
    )]
    public MailingListMemberAction $action;

    #[ManyToOne(
        targetEntity: MailingList::class,
        inversedBy: 'auditEntries',
    )]
    #[JoinColumn(
        name: 'mailing_list',
        referencedColumnName: 'name',
        onDelete: 'cascade',
        nullable: true,
    )]
    public MailingList $mailingList;

    #[Column(type: Types::STRING)]
    public string $email;

    #[Column(
        type: Types::STRING,
        enumType: MailingListMemberOrigin::class,
    )]
    public MailingListMemberOrigin $origin;

    public static function create(
        MailingListMemberAction $action,
        MailingListMemberOrigin $origin,
        Member $member,
        MailingList $mailingList,
        string $email,
        ?Member $user = null,
    ): self {
        $audit = new self();
        $audit->action = $action;
        $audit->origin = $origin;
        $audit->setMember($member);
        $audit->mailingList = $mailingList;
        $audit->email = $email;
        $audit->user = $user;

        return $audit;
    }

    #[Override]
    protected function getStringBodyFormatted(): string
    {
        return self::BODY_FORMAT;
    }

    /**
     * @return array<string>
     */
    #[Override]
    protected function getStringArguments(): array
    {
        // The body is translated and these are substituted into it afterwards, so they go in as their source string.
        return [
            $this->action->getName()->getMessage(),
            $this->email,
            $this->mailingList->name,
            $this->origin->getName()->getMessage(),
        ];
    }
}
