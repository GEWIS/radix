<?php

declare(strict_types=1);

namespace App\Entity\Database;

use App\Repository\Database\MemberUpdateRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Member update model.
 */
#[Entity(repositoryClass: MemberUpdateRepository::class)]
class MemberUpdate
{
    /**
     * The member we want to update.
     */
    #[Id]
    #[OneToOne(targetEntity: Member::class)]
    #[JoinColumn(
        name: 'lidnr',
        referencedColumnName: 'lidnr',
    )]
    private Member $member;

    /**
     * When the update was requested.
     */
    #[Column(type: 'date_immutable')]
    private DateTimeImmutable $requestedDate;

    /**
     * Member's email address.
     */
    #[Column(type: 'string')]
    public string $email;

    /**
     * Member's last name.
     */
    #[Column(type: 'string')]
    public string $lastName;

    /**
     * Middle name.
     */
    #[Column(type: 'string')]
    public string $middleName;

    /**
     * Initials.
     */
    #[Column(type: 'string')]
    public string $initials;

    /**
     * First name.
     */
    #[Column(type: 'string')]
    public string $firstName;

    /**
     * Get the member.
     *
     * @psalm-ignore-nullable-return
     */
    public function getMember(): ?Member
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
     * Convert most relevant items to array.
     *
     * @return array{
     *     email: string,
     *     lastName: string,
     *     middleName: string,
     *     initials: string,
     *     firstName: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'lastName' => $this->lastName,
            'middleName' => $this->middleName,
            'initials' => $this->initials,
            'firstName' => $this->firstName,
        ];
    }
}
