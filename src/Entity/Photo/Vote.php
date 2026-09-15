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

/**
 * Vote, represents a vote for a photo of the week.
 *
 * @phpstan-import-type PhotoGdprArrayType from Photo as ImportedPhotoGdprArrayType
 * @phpstan-type VoteGdprArrayType = array{
 *     id: ?int,
 *     dateTime: string,
 *     photo: ImportedPhotoGdprArrayType,
 * }
 */
#[Entity]
class Vote
{
    use IdentifiableTrait;

    /**
     * Date and time when the photo was voted for.
     */
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public private(set) DateTimeImmutable $dateTime;

    /**
     * @param MemberModel $voter The member who voted
     */
    public function __construct(
        #[ManyToOne(
            targetEntity: Photo::class,
            inversedBy: 'votes',
        )]
        #[JoinColumn(
            name: 'photo_id',
            referencedColumnName: 'id',
            nullable: false,
        )]
        private Photo $photo,
        #[ManyToOne(targetEntity: MemberModel::class)]
        #[JoinColumn(
            name: 'voter_id',
            referencedColumnName: 'lidnr',
            nullable: false,
            onDelete: 'CASCADE',
        )]
        private MemberModel $voter,
    ) {
        $this->dateTime = new DateTimeImmutable();
    }

    public function setPhoto(Photo $photo): void
    {
        $this->photo = $photo;
    }

    public function getPhoto(): Photo
    {
        return $this->photo;
    }

    /**
     * @return VoteGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'id' => $this->id,
            'dateTime' => $this->dateTime->format(DateTimeInterface::ATOM),
            'photo' => $this->getPhoto()->toGdprArray(),
        ];
    }
}
