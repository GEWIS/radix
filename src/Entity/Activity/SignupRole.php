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
    public SignupList $signupList;

    #[Column(type: Types::STRING)]
    public string $name = '';

    #[Column(type: Types::INTEGER)]
    public int $minimum = 1;

    #[Column(
        type: Types::INTEGER,
        options: ['default' => 0],
    )]
    public int $position = 0;
}
