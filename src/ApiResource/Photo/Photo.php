<?php

declare(strict_types=1);

namespace App\ApiResource\Photo;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Photo\AlbumPhotoProvider;

#[ApiResource(
    shortName: 'AlbumPhoto',
    description: 'A photo in a published album. Reached through its album, which is what decides whether it may be '
        . 'seen.',
    operations: [
        new GetCollection(
            uriTemplate: '/photos/albums/{id}/photos',
            uriVariables: [
                'id' => new Link(
                    fromClass: Album::class,
                    identifiers: ['id'],
                    parameterName: 'id',
                ),
            ],
            requirements: ['id' => '\d+'],
            openapi: new OpenApiOperation(
                responses: [
                    404 => new OpenApiResponse(
                        'No album is stored under that identifier, or it has not been published.',
                    ),
                ],
                summary: 'Get the photos of an album',
                description: 'The photos of one published album, oldest first, paged. Photos of a sub-album belong '
                    . 'to that sub-album and are read from its own collection. An album that does not exist, or that '
                    . 'has not been published, is a missing resource.',
            ),
            security: "is_granted('" . ApiPermissions::PhotoAlbumsR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::PhotoAlbumsR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::V5_0_0],
            name: self::OPERATION_ALBUM_COLLECTION,
        ),
    ],
    provider: AlbumPhotoProvider::class,
)]
final readonly class Photo
{
    public const string OPERATION_ALBUM_COLLECTION = 'api_photos_album_photos';

    /**
     * @param array<array-key, array<string, mixed>> $tags
     * @phpstan-param list<array{
     *     lidnr: int,
     *     full_name: string,
     * }|array{
     *     organId: int,
     *     abbreviation: string,
     * }> $tags
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        #[ApiProperty(description: 'When the photo was taken, in the `Y-m-d\TH:i:sP` format.')]
        public string $dateTime,
        #[ApiProperty(description: 'Who took the photo, as the camera recorded it.')]
        public ?string $artist,
        public ?string $camera,
        #[ApiProperty(description: 'The photo\'s height divided by its width, as measured when it was uploaded.')]
        public ?float $aspectRatio,
        #[ApiProperty(
            description: 'Where the image itself is served from, for the same bearer token that read this '
                . 'collection. The last segment of that address is the rendition; swap it for another of the '
                . 'pipeline\'s variants to be served the photo at a different size.',
        )]
        public string $url,
        #[ApiProperty(
            description: 'Who and what is in the photo: a member tag names the member, a body tag the body.',
            openapiContext: [
                'type' => 'array',
                'items' => [
                    'oneOf' => [
                        [
                            'type' => 'object',
                            'properties' => [
                                'lidnr' => ['type' => 'integer'],
                                'full_name' => ['type' => 'string'],
                            ],
                        ],
                        [
                            'type' => 'object',
                            'properties' => [
                                'organId' => ['type' => 'integer'],
                                'abbreviation' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        )]
        public array $tags,
    ) {
    }
}
