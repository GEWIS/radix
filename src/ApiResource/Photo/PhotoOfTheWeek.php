<?php

declare(strict_types=1);

namespace App\ApiResource\Photo;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Photo\PhotoOfTheWeekProvider;

#[ApiResource(
    shortName: 'PhotoOfTheWeek',
    description: 'The photo the association voted best of the week that just passed. Its public copy needs no '
        . 'token; a hidden photo of the week is a missing resource.',
    operations: [
        new Get(
            uriTemplate: '/photos/photo-of-the-week',
            uriVariables: [],
            openapi: new OpenApiOperation(
                summary: 'Get the photo of the week',
                description: 'The photo that won the vote of the week that just passed, with the address its public '
                    . 'copy is served from. A week nobody voted in, and a photo of the week that has been hidden, '
                    . 'are both a missing resource.',
            ),
            security: "is_granted('" . ApiPermissions::PhotoOfTheWeekR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::PhotoOfTheWeekR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_ITEM,
        ),
    ],
    provider: PhotoOfTheWeekProvider::class,
)]
final readonly class PhotoOfTheWeek
{
    public const string OPERATION_ITEM = 'api_photos_photo_of_the_week';

    /**
     * @param array<string, mixed> $photo
     * @phpstan-param array{
     *     id: int,
     *     dateTime: string,
     *     artist: string|null,
     *     camera: string|null,
     *     aspectRatio: float|null,
     *     album: array{
     *         id: int,
     *         name: string,
     *     },
     * } $photo
     */
    public function __construct(
        #[ApiProperty(description: 'The first day of the week that was voted on, in the `Y-m-d\TH:i:sP` format.')]
        public string $week,
        #[ApiProperty(
            description: 'The photo itself, and the album it was taken out of.',
            openapiContext: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'dateTime' => [
                        'type' => 'string',
                        'format' => 'date-time',
                    ],
                    'artist' => [
                        'type' => [
                            'string',
                            'null',
                        ],
                    ],
                    'camera' => [
                        'type' => [
                            'string',
                            'null',
                        ],
                    ],
                    'aspectRatio' => [
                        'type' => [
                            'number',
                            'null',
                        ],
                    ],
                    'album' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        )]
        public array $photo,
        #[ApiProperty(
            description: 'The public copy of the photo of the week, which needs no credential of any kind: it is '
                . 'the same address the association\'s own front page serves it from to a visitor who is not logged '
                . 'in. Null while that copy is not on disk, which is what a hidden photo of the week looks like on '
                . 'the filesystem.',
        )]
        public ?string $url,
    ) {
    }
}
