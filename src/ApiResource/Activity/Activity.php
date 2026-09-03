<?php

declare(strict_types=1);

namespace App\ApiResource\Activity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Activity\Enums\ActivityCategories;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Activity\ActivityProvider;
use App\State\Api\ApiVersion;

/**
 * @phpstan-type ActivityApiText = array{
 *     en: string|null,
 *     nl: string|null,
 * }
 * @phpstan-type ActivityApiOrgan = array{
 *     id: int,
 *     abbreviation: string,
 *     name: string,
 * }
 * @phpstan-type ActivityApiCompany = array{
 *     id: int,
 *     name: string,
 * }
 * @phpstan-type ActivityApiLabel = array{
 *     id: int,
 *     name: ActivityApiText,
 * }
 * @phpstan-type ActivityApiSignupList = array{
 *     id: int,
 *     name: ActivityApiText,
 *     openDate: string,
 *     closeDate: string,
 *     onlyGEWIS: bool,
 *     limitedCapacity: bool,
 *     capacity: int|null,
 * }
 */
#[ApiResource(
    shortName: 'Activity',
    description: 'An activity, as its live (approved) revision describes it. A cancelled activity is still listed, '
        . 'with `cancelled` set; one the board unpublished is not listed at all. Every human-readable field '
        . 'is an {en, nl} pair, and a value the organiser never filled in is null rather than substituted '
        . 'from the other language.',
    operations: [
        new GetCollection(
            uriTemplate: '/activities',
            openapi: new OpenApiOperation(
                summary: 'Get activities',
                description: 'The publicly visible activities, paged. Upcoming ones by default, the one starting '
                    . 'soonest first; `past=true` asks for the ones that have already finished, the most recent '
                    . 'first. `category` narrows to a single category and `organ` to the body organising it, by its '
                    . 'identifier; a `category` naming no known category is ignored rather than refused. A cancelled '
                    . 'activity is listed, with `cancelled` set; an activity the board unpublished is not listed at '
                    . 'all.',
                parameters: [
                    new Parameter(
                        name: 'past',
                        in: 'query',
                        description: 'Ask for the activities that have already finished, most recent first, instead '
                            . 'of the upcoming ones. A value that is not recognisably true is read as false '
                            . 'rather than refused.',
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                    new Parameter(
                        name: 'category',
                        in: 'query',
                        description: 'Narrow the list to a single category, as `category` on an activity states it. '
                            . 'A value naming no known category is ignored rather than refused.',
                        schema: ['$ref' => '#/components/schemas/ActivityCategoryEnum'],
                    ),
                    new Parameter(
                        name: 'organ',
                        in: 'query',
                        description: 'Narrow the list to the body organising the activity, by the id `/bodies/{id}` '
                            . 'answers to. A value that is not a whole number is ignored rather than refused.',
                        schema: ['type' => 'integer'],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::ActivitiesR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::ActivitiesR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::V5_0_0],
            name: self::OPERATION_COLLECTION,
        ),
        new Get(
            uriTemplate: '/activities/{id}',
            requirements: ['id' => '\d+'],
            openapi: new OpenApiOperation(
                summary: 'Get an activity',
                description: 'A single activity by identifier. An activity that has never been approved, and one the '
                    . 'board has unpublished, are both not public and answer 404 — the same as an identifier that '
                    . 'names nothing.',
            ),
            security: "is_granted('" . ApiPermissions::ActivitiesR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::ActivitiesR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::V5_0_0],
            name: self::OPERATION_ITEM,
        ),
    ],
    provider: ActivityProvider::class,
)]
final readonly class Activity
{
    public const string OPERATION_COLLECTION = 'api_activities';
    public const string OPERATION_ITEM = 'api_activity';

    private const array SCHEMA_TEXT = [
        'type' => 'object',
        'properties' => [
            'en' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
            'nl' => [
                'type' => [
                    'string',
                    'null',
                ],
            ],
        ],
    ];

    /**
     * @param ActivityApiText             $name
     * @param ActivityApiText             $description
     * @param ActivityApiText             $location
     * @param ActivityApiText             $costs
     * @param ActivityApiOrgan|null       $organ
     * @param ActivityApiCompany|null     $company
     * @param list<ActivityApiLabel>      $labels
     * @param list<ActivityApiSignupList> $signupLists
     */
    public function __construct(
        #[ApiProperty(
            description: 'Identifier of the activity. Stable across every revision of it, so it keeps naming the '
                . 'same activity after an edit is approved.',
            identifier: true,
        )]
        public int $id,
        #[ApiProperty(
            description: 'The name of the activity, per language.',
            openapiContext: self::SCHEMA_TEXT,
        )]
        public array $name,
        #[ApiProperty(
            description: 'The description of the activity, per language. Markdown, as the organiser wrote it.',
            openapiContext: self::SCHEMA_TEXT,
        )]
        public array $description,
        #[ApiProperty(
            description: 'Where the activity takes place, per language.',
            openapiContext: self::SCHEMA_TEXT,
        )]
        public array $location,
        #[ApiProperty(
            description: 'What attending costs, per language. Prose rather than an amount: an activity may be free, '
                . 'priced per option, or settled afterwards.',
            openapiContext: self::SCHEMA_TEXT,
        )]
        public array $costs,
        #[ApiProperty(description: 'When the activity starts, in the `Y-m-d\TH:i:sP` format.')]
        public string $beginTime,
        #[ApiProperty(description: 'When the activity ends, in the `Y-m-d\TH:i:sP` format.')]
        public string $endTime,
        #[ApiProperty(
            description: 'The single category the activity is filed under. `uncategorised` is only ever carried by '
                . 'activities that predate categories.',
            openapiContext: ['$ref' => '#/components/schemas/ActivityCategoryEnum'],
        )]
        public ActivityCategories $category,
        #[ApiProperty(
            description: 'The body organising the activity, or null when no body does.',
            openapiContext: [
                'type' => [
                    'object',
                    'null',
                ],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'abbreviation' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
            ],
        )]
        public ?array $organ,
        #[ApiProperty(
            description: 'The company organising the activity, or null when no company does. Naming a company is '
                . 'presentation only; it never means the company owns the activity.',
            openapiContext: [
                'type' => [
                    'object',
                    'null',
                ],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ],
        )]
        public ?array $company,
        #[ApiProperty(description: 'Whether the activity needs the association\'s photography committee present.')]
        public bool $requireGEFLITST,
        #[ApiProperty(description: 'Whether the activity needs a card terminal.')]
        public bool $requireZettle,
        #[ApiProperty(
            description: 'Whether the board has cancelled the activity. A cancelled activity stays announced and '
                . 'keeps its schedule; all sign-up interaction on it is frozen.',
        )]
        public bool $cancelled,
        #[ApiProperty(
            description: 'The labels the activity carries, e.g. that it is in English or that it is alcohol-free.',
            openapiContext: [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => self::SCHEMA_TEXT,
                    ],
                ],
            ],
        )]
        public array $labels = [],
        #[ApiProperty(
            description: 'The sign-up lists of the live revision, with the window each is open for. Empty when the '
                . 'activity is not signed up for. `capacity` is only meaningful with `limitedCapacity`.',
            openapiContext: [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => self::SCHEMA_TEXT,
                        'openDate' => [
                            'type' => 'string',
                            'format' => 'date-time',
                        ],
                        'closeDate' => [
                            'type' => 'string',
                            'format' => 'date-time',
                        ],
                        'onlyGEWIS' => ['type' => 'boolean'],
                        'limitedCapacity' => ['type' => 'boolean'],
                        'capacity' => [
                            'type' => [
                                'integer',
                                'null',
                            ],
                        ],
                    ],
                ],
            ],
        )]
        public array $signupLists = [],
    ) {
    }
}
