<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Entity\Database\Enums\AddressTypes;
use App\Entity\Database\Enums\MemberDetailAction;
use App\Repository\Database\AuditAddressChangeRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;

#[Entity(repositoryClass: AuditAddressChangeRepository::class)]
class AuditAddressChange extends AuditEntry
{
    protected const bool IMMUTABLE = true;

    private const string BODY_FORMAT = '<strong>%s address</strong> of <emph>%s</emph> was %s';

    #[Column(
        type: 'string',
        enumType: AddressTypes::class,
    )]
    private AddressTypes $addressType;

    /**
     * Named apart from the `action` of a mailing list subscription, which is a column of the same table.
     */
    #[Column(
        type: 'string',
        enumType: MemberDetailAction::class,
    )]
    private MemberDetailAction $detailAction;

    public static function create(
        Member $member,
        AddressTypes $addressType,
        MemberDetailAction $action,
        ?Member $user = null,
    ): self {
        $audit = new self();
        $audit->setMember($member);
        $audit->setAddressType($addressType);
        $audit->setAction($action);
        $audit->setUser($user);

        return $audit;
    }

    public function getAddressType(): AddressTypes
    {
        return $this->addressType;
    }

    public function setAddressType(AddressTypes $addressType): void
    {
        $this->addressType = $addressType;
    }

    public function getAction(): MemberDetailAction
    {
        return $this->detailAction;
    }

    public function setAction(MemberDetailAction $action): void
    {
        $this->detailAction = $action;
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
        return [
            $this->getAddressType()->value,
            $this->getMember()?->getFullName() ?? '-',
            $this->getAction()->value,
        ];
    }
}
