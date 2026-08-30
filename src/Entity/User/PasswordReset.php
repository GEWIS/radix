<?php

declare(strict_types=1);

namespace App\Entity\User;

use App\Entity\Application\Traits\SelectorTokenTrait;
use App\Entity\Application\Traits\TempHashTrait;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserTypes;
use App\Repository\User\PasswordResetRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use InvalidArgumentException;

#[Entity(repositoryClass: PasswordResetRepository::class)]
#[Index(
    columns: ['selector'],
    name: 'IDX_password_reset_selector',
)]
#[Index(
    columns: ['tempHash'],
    name: 'IDX_password_reset_temp_hash',
)]
class PasswordReset
{
    use SelectorTokenTrait;
    use TempHashTrait;

    #[Id]
    #[GeneratedValue]
    #[Column]
    public private(set) ?int $id = null;

    #[Column(
        type: Types::STRING,
        enumType: UserTypes::class,
    )]
    public private(set) UserTypes $userType;

    #[ManyToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
        nullable: true,
        onDelete: 'CASCADE',
    )]
    public private(set) ?Member $member = null;

    #[ManyToOne(targetEntity: CompanyUser::class)]
    #[JoinColumn(
        nullable: true,
        onDelete: 'CASCADE',
    )]
    public private(set) ?CompanyUser $companyUser = null;

    public function __construct(
        DateTimeImmutable $expiresAt,
        string $selector,
        string $hashedToken,
        ?Member $member = null,
        ?CompanyUser $companyUser = null,
    ) {
        if (
            null === $member
            && null === $companyUser
        ) {
            throw new InvalidArgumentException('Either $member or $companyUser must be provided');
        }

        if (
            null !== $member
            && null !== $companyUser
        ) {
            throw new InvalidArgumentException('Only one of $member or $companyUser should be provided');
        }

        $this->expiresAt = $expiresAt;
        $this->selector = $selector;
        $this->hashedToken = $hashedToken;
        $this->member = $member;
        $this->companyUser = $companyUser;
        $this->userType = null !== $member
            ? UserTypes::User
            : UserTypes::CompanyUser;
    }
}
