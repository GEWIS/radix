<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Decision\Member as MemberModel;
use App\Repository\Activity\UserSignupRepository;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Override;

/**
 * Signup model.
 */
#[Entity(repositoryClass: UserSignupRepository::class)]
class UserSignup extends Signup
{
    /**
     * Who is subscribed. The column is nullable at the database level only because {@see ExternalSignup} shares the
     * single `Signup` table and has no member; a UserSignup always has one. {@see \App\Service\Activity\SignupManager}
     * is the only place one is built and it sets the member, and the cascade below takes the sign-up with the member,
     * so no row can outlive the member it names and hydrate into an uninitialised property.
     */
    #[ManyToOne(targetEntity: MemberModel::class)]
    #[JoinColumn(
        name: 'user_lidnr',
        referencedColumnName: 'lidnr',
        onDelete: 'CASCADE',
    )]
    private MemberModel $user;

    /**
     * Get the full name of the user whom signed up for the activity.
     */
    #[Override]
    public function getFullName(): string
    {
        return $this->getUser()->getFullName();
    }

    /**
     * Get the user that is signed up.
     */
    public function getUser(): MemberModel
    {
        return $this->user;
    }

    /**
     * Set the user for the activity signup.
     */
    public function setUser(MemberModel $user): void
    {
        $this->user = $user;
    }

    /**
     * Get the email address of the user whom signed up for the activity.
     */
    #[Override]
    public function getEmail(): ?string
    {
        return $this->getUser()->getEmail();
    }
}
