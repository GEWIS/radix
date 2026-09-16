<?php

declare(strict_types=1);

namespace App\Entity\Photo;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Decision\Member as MemberModel;
use App\Repository\Photo\HiddenPhotoRepository;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * A photo a member has hidden from their own photo page. The flag is stored here, on the (member, photo) pair, rather
 * than on the {@see MemberTag} so that removing and re-adding the tag cannot bring one back. Whether hidden photos are
 * actually withheld from other viewers depends on the member's {@see \App\Entity\User\Enums\PhotoVisibility}.
 */
#[Entity(repositoryClass: HiddenPhotoRepository::class)]
#[Table(name: 'HiddenPhoto')]
#[UniqueConstraint(
    name: 'hidden_photo_uniq',
    columns: [
        'member_id',
        'photo_id',
    ],
)]
class HiddenPhoto
{
    use IdentifiableTrait;

    #[ManyToOne(
        targetEntity: MemberModel::class,
    )]
    #[JoinColumn(
        name: 'member_id',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public MemberModel $member;

    #[ManyToOne(
        targetEntity: Photo::class,
        inversedBy: 'hiddenBy',
    )]
    #[JoinColumn(
        name: 'photo_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public Photo $photo;
}
