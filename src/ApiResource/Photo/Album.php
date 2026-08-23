<?php

declare(strict_types=1);

namespace App\ApiResource\Photo;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Photo\AlbumProvider;

#[ApiResource(
    shortName: 'PhotoAlbum',
    description: 'A published photo album. Sub-albums are listed alongside their parents and carry their parent\'s '
        . 'identifier, so a consumer can rebuild the tree from one listing.',
    operations: [
        new GetCollection(
            uriTemplate: '/photos/albums',
            openapi: new OpenApiOperation(
                summary: 'Get photo albums',
                description: 'Every published photo album, sub-albums included, most recent first. An album with no '
                    . 'date sorts last.',
            ),
            security: "is_granted('" . ApiPermissions::PhotoAlbumsR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::PhotoAlbumsR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_COLLECTION,
        ),
        new Get(
            uriTemplate: '/photos/albums/{id}',
            requirements: ['id' => '\d+'],
            openapi: new OpenApiOperation(
                summary: 'Get a photo album',
                description: 'A single published photo album with the published albums below it. Its photos are '
                    . 'paged, and are read from `/api/photos/albums/{id}/photos`, which `photosUrl` addresses.',
            ),
            security: "is_granted('" . ApiPermissions::PhotoAlbumsR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::PhotoAlbumsR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_ITEM,
        ),
    ],
    provider: AlbumProvider::class,
)]
final readonly class Album
{
    public const string OPERATION_COLLECTION = 'api_photos_albums';
    public const string OPERATION_ITEM = 'api_photos_album';

    /**
     * @param array<array-key, array<string, mixed>>|null $children
     * @phpstan-param list<array{
     *     id: int,
     *     name: string,
     *     startDateTime: string|null,
     *     endDateTime: string|null,
     *     photoCount: int,
     *     albumCount: int,
     *     coverUrl: string|null,
     * }>|null $children
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public string $name,
        #[ApiProperty(
            description: 'When the earliest photo in the album was taken, in the `Y-m-d\TH:i:sP` format. Null for an '
                . 'album that holds no dated photos yet.',
        )]
        public ?string $startDateTime,
        #[ApiProperty(description: 'When the last photo in the album was taken, in the `Y-m-d\TH:i:sP` format.')]
        public ?string $endDateTime,
        #[ApiProperty(description: 'The album this one sits below, or null for a top-level album.')]
        public ?int $parent,
        #[ApiProperty(
            description: 'The photos in this album itself, which is what `/api/photos/albums/{id}/photos` pages '
                . 'through. Photos of a sub-album count towards that sub-album.',
        )]
        public int $photoCount,
        #[ApiProperty(description: 'The number of published albums directly below this one.')]
        public int $albumCount,
        #[ApiProperty(
            description: 'Where the album\'s cover mosaic is served from, for the same bearer token that read this '
                . 'album. The last segment of that address is the rendition; swap it for another of the pipeline\'s '
                . 'variants to be served the cover at a different size. Null for an album no cover has been '
                . 'generated for yet.',
        )]
        public ?string $coverUrl,
        #[ApiProperty(
            description: 'The published albums directly below this one, each described as it would be in the '
                . 'listing. Null rather than empty on the listing itself, where `albumCount` says how many there '
                . 'are: a listing that carried every album\'s children would carry the whole tree twice over.',
            openapiContext: [
                'type' => [
                    'array',
                    'null',
                ],
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'startDateTime' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                            'format' => 'date-time',
                        ],
                        'endDateTime' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                            'format' => 'date-time',
                        ],
                        'photoCount' => ['type' => 'integer'],
                        'albumCount' => ['type' => 'integer'],
                        'coverUrl' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                        ],
                    ],
                ],
            ],
        )]
        public ?array $children = null,
        #[ApiProperty(
            description: 'Where the photos of this album are read from, paged. Null on the listing, for the same '
                . 'reason `children` is.',
        )]
        public ?string $photosUrl = null,
    ) {
    }
}
