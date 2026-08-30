<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\AuditEmailChangeRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Override;

#[Entity(repositoryClass: AuditEmailChangeRepository::class)]
class AuditEmailChange extends AuditEntry
{
    protected const bool IMMUTABLE = true;

    private const string BODY_FORMAT = '<strong>Changed email address</strong> of <emph>%s</emph> from '
        . '<emph>%s</emph> to <emph>%s</emph>';

    #[Column(
        type: 'string',
        nullable: true,
    )]
    public ?string $oldEmail = null;

    #[Column(type: 'string')]
    public string $newEmail;

    public static function create(
        Member $member,
        ?string $oldEmail,
        string $newEmail,
        ?Member $user = null,
    ): self {
        $audit = new self();
        $audit->setMember($member);
        $audit->oldEmail = $oldEmail;
        $audit->newEmail = $newEmail;
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
        return [
            $this->member?->getFullName() ?? '-',
            $this->oldEmail ?? '-',
            $this->newEmail,
        ];
    }
}
