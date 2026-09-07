<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Repository\Activity\SignupRoleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

#[Entity(repositoryClass: SignupRoleRepository::class)]
class SignupRole
{
    use IdentifiableTrait;

    #[ManyToOne(
        targetEntity: SignupList::class,
        cascade: ['persist'],
        inversedBy: 'roles',
    )]
    #[JoinColumn(
        name: 'signuplist_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    private SignupList $signupList;

    #[Column(type: Types::STRING)]
    private string $name = '';

    #[Column(type: Types::INTEGER)]
    private int $minimum = 1;

    #[Column(
        type: Types::INTEGER,
        options: ['default' => 0],
    )]
    private int $position = 0;

    public function getSignupList(): SignupList
    {
        return $this->signupList;
    }

    public function setSignupList(SignupList $signupList): void
    {
        $this->signupList = $signupList;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getMinimum(): int
    {
        return $this->minimum;
    }

    public function setMinimum(int $minimum): void
    {
        $this->minimum = $minimum;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
