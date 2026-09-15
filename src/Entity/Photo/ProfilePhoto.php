<?php

declare(strict_types=1);

namespace App\Entity\Photo;

use App\Entity\Application\Traits\IdentifiableTrait;
use App\Entity\Decision\Member as MemberModel;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * ProfilePhoto.
 *
 * @phpstan-import-type PhotoGdprArrayType from Photo as ImportedPhotoGdprArrayType
 * @phpstan-type ProfilePhotoGdprArrayType = array{
 *     dateTime: string,
 *     explicit: bool,
 *     photo: ImportedPhotoGdprArrayType,
 * }
 */
#[Entity]
class ProfilePhoto
{
    use IdentifiableTrait;

    #[ManyToOne(
        targetEntity: Photo::class,
        inversedBy: 'profilePhotos',
    )]
    #[JoinColumn(
        name: 'photo_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public Photo $photo;

    /**
     * The member this is the profile photo of. A profile photo is a picture of one person and says nothing without
     * them, so it goes when they do.
     */
    #[OneToOne(targetEntity: MemberModel::class)]
    #[JoinColumn(
        name: 'member_id',
        referencedColumnName: 'lidnr',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    public MemberModel $member;

    /**
     * Date and time when the photo was taken.
     */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $dateTime;

    /**
     * Date and time when the photo was taken.
     */
    #[Column(type: Types::BOOLEAN)]
    public bool $explicit;

    /**
     * @return ProfilePhotoGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'dateTime' => $this->dateTime->format(DateTimeInterface::ATOM),
            'explicit' => $this->explicit,
            'photo' => $this->photo->toGdprArray(),
        ];
    }
}
