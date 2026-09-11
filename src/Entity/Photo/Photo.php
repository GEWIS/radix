<?php

declare(strict_types=1);

namespace App\Entity\Photo;

use App\Entity\Application\Traits\IdentifiableTrait;
use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * Photo.
 *
 * @phpstan-type PhotoGdprArrayType = array{
 *     id: ?int,
 *     dateTime: string,
 *     path: string,
 * }
 * @phpstan-type PhotoExifArrayType = array{
 *     artist: ?string,
 *     camera: ?string,
 *     dateTime: string,
 *     flash: ?bool,
 *     focalLength: ?float,
 *     shutterSpeed: ?string,
 *     aperture: ?string,
 *     iso: ?int,
 *     latitude: ?float,
 *     longitude: ?float,
 * }
 */
#[Entity]
class Photo
{
    use IdentifiableTrait;

    /**
     * Date and time when the photo was taken.
     */
    #[Column(type: Types::DATETIME_MUTABLE)]
    public DateTime $dateTime;

    /**
     * Artist/author.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $artist = null;

    /**
     * The type of camera used.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $camera = null;

    /**
     * Whether a flash has been used.
     */
    #[Column(
        type: Types::BOOLEAN,
        nullable: true,
    )]
    public ?bool $flash = null;

    /**
     * The focal length of the lens, in mm.
     */
    #[Column(
        type: Types::FLOAT,
        nullable: true,
    )]
    public ?float $focalLength = null;

    /**
     * The exposure time, in seconds.
     */
    #[Column(
        type: Types::FLOAT,
        nullable: true,
    )]
    public ?float $exposureTime = null;

    /**
     * The shutter speed.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $shutterSpeed = null;

    /**
     * The lens aperture.
     */
    #[Column(
        type: Types::STRING,
        nullable: true,
    )]
    public ?string $aperture = null;

    /**
     * Indicates the ISO Speed and ISO Latitude of the camera.
     */
    #[Column(
        type: Types::SMALLINT,
        nullable: true,
    )]
    public ?int $iso = null;

    /**
     * Album in which the photo is.
     */
    #[ManyToOne(
        targetEntity: Album::class,
        inversedBy: 'photos',
    )]
    #[JoinColumn(
        name: 'album_id',
        referencedColumnName: 'id',
        nullable: false,
    )]
    public Album $album;

    /**
     * The path where the photo is located relative to the storage directory.
     */
    #[Column(type: Types::STRING)]
    public string $path;

    /**
     * The GPS longitude of the location where the photo was taken.
     */
    #[Column(
        type: Types::FLOAT,
        nullable: true,
    )]
    public ?float $longitude = null;

    /**
     * The GPS latitude of the location where the photo was taken.
     */
    #[Column(
        type: Types::FLOAT,
        nullable: true,
    )]
    public ?float $latitude = null;

    /**
     * All the votes for this photo.
     *
     * @var Collection<array-key, Vote>
     */
    #[OneToMany(
        targetEntity: Vote::class,
        mappedBy: 'photo',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    private Collection $votes;

    /**
     * All the tags for this photo.
     *
     * @var Collection<array-key, Tag>
     */
    #[OneToMany(
        targetEntity: Tag::class,
        mappedBy: 'photo',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    private Collection $tags;

    /**
     * All the profile photos that use this photo.
     *
     * @var Collection<array-key, ProfilePhoto>
     */
    #[OneToMany(
        targetEntity: ProfilePhoto::class,
        mappedBy: 'photo',
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $profilePhotos;

    /**
     * The rows hiding this photo from a member's photo page.
     *
     * @var Collection<array-key, HiddenPhoto>
     */
    #[OneToMany(
        targetEntity: HiddenPhoto::class,
        mappedBy: 'photo',
        cascade: [
            'persist',
            'remove',
        ],
        fetch: 'EXTRA_LAZY',
    )]
    private Collection $hiddenBy;

    /**
     * The corresponding WeeklyPhoto entity if this photo has been a weekly photo.
     */
    #[OneToOne(
        targetEntity: WeeklyPhoto::class,
        mappedBy: 'photo',
        cascade: [
            'persist',
            'remove',
        ],
    )]
    public private(set) ?WeeklyPhoto $weeklyPhoto = null;

    /**
     * The aspect ratio of the photo width/height.
     */
    #[Column(
        type: Types::FLOAT,
        nullable: true,
    )]
    public ?float $aspectRatio = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->votes = new ArrayCollection();
        $this->profilePhotos = new ArrayCollection();
        $this->hiddenBy = new ArrayCollection();
    }

    /**
     * @return Collection<array-key, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * Add a tag to a photo.
     */
    public function addTag(Tag $tag): void
    {
        $tag->photo = $this;
        $this->tags[] = $tag;
    }

    /**
     * @return Collection<array-key, ProfilePhoto>
     */
    public function getProfilePhotos(): Collection
    {
        return $this->profilePhotos;
    }

    /**
     * @return PhotoGdprArrayType
     */
    public function toGdprArray(): array
    {
        return [
            'id' => $this->getId(),
            'dateTime' => $this->dateTime->format(DateTimeInterface::ATOM),
            'path' => $this->path,
        ];
    }

    /**
     * The camera metadata for the viewer's info panel, keyed to match the frontend Exif shape.
     *
     * @return PhotoExifArrayType
     */
    public function toExifArray(): array
    {
        return [
            'artist' => $this->artist,
            'camera' => $this->camera,
            'dateTime' => $this->dateTime->format('Y-m-d H:i:s'),
            'flash' => $this->flash,
            'focalLength' => $this->focalLength,
            'shutterSpeed' => $this->shutterSpeed,
            'aperture' => $this->aperture,
            'iso' => $this->iso,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
