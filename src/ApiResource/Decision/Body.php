<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Decision\BodyProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'Body',
    description: 'A body of the association: a committee, a fraternity, an advisory board, or any other organ a '
        . 'decision founded. The register calls it an organ; the API calls it a body. An abbreviation is '
        . 'reused when a body is founded again years later, so the identifier is the only stable handle on '
        . 'one.',
    operations: [
        new GetCollection(
            uriTemplate: '/bodies',
            openapi: new OpenApiOperation(
                summary: 'Get bodies',
                description: 'The bodies of the association, paged and ordered by abbreviation. Only the bodies that '
                    . 'still exist, unless `includeAbrogated` asks for the abrogated ones as well; `type` narrows the '
                    . 'list to one kind of body, and a value naming no kind is ignored rather than refused.',
                parameters: [
                    new Parameter(
                        name: 'type',
                        in: 'query',
                        description: 'Narrow the list to one kind of body: `committee`, `avc`, `fraternity`, `kcc`, '
                            . '`avw`, `rva` or `sc`. A value naming no kind of body is ignored rather than refused.',
                        schema: ['type' => 'string'],
                    ),
                    new Parameter(
                        name: 'includeAbrogated',
                        in: 'query',
                        description: 'Also list the bodies that have been abrogated; they are told apart by '
                            . '`active`. A value that is not recognisably true is read as false rather than refused.',
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::BodiesR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::BodiesR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_COLLECTION,
        ),
        new Get(
            uriTemplate: '/bodies/{id}',
            requirements: ['id' => self::ID_REQUIREMENT],
            openapi: new OpenApiOperation(
                summary: 'Get a body',
                description: 'A single body by id, abrogated or not: a consumer that already holds the id is asking '
                    . 'about that body in particular, and whether it still exists is the answer rather than the '
                    . 'question. An id no body is stored under is a missing resource: 404.',
            ),
            security: "is_granted('" . ApiPermissions::BodiesR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::BodiesR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_ITEM,
        ),
    ],
    provider: BodyProvider::class,
)]
final readonly class Body
{
    public const string OPERATION_COLLECTION = 'api_bodies';
    public const string OPERATION_ITEM = 'api_body';

    public const string ID_REQUIREMENT = '\d+';

    public function __construct(
        #[SerializedName('id')]
        #[ApiProperty(
            description: 'The identifier the register gives the body. An abbreviation is reused when a body is '
                . 'founded again years after the last one was abrogated, so this is the only stable handle on one.',
            identifier: true,
        )]
        public int $id,
        #[SerializedName('abbreviation')]
        #[ApiProperty(description: 'What the body is called in short, and what its members call it.')]
        public string $abbreviation,
        #[SerializedName('name')]
        public string $name,
        #[SerializedName('type')]
        #[ApiProperty(
            description: 'The kind of body: one of `committee`, `avc`, `fraternity`, `kcc`, `avw`, `rva` or `sc`.',
        )]
        public string $type,
        #[SerializedName('foundationDate')]
        #[ApiProperty(description: 'When the founding decision took effect, in the `Y-m-d\TH:i:sP` format.')]
        public string $foundationDate,
        #[SerializedName('abrogationDate')]
        #[ApiProperty(
            description: 'When the body was abrogated, in the `Y-m-d\TH:i:sP` format, or null if no decision has '
                . 'abrogated it. A date in the future is a body that is still running.',
        )]
        public ?string $abrogationDate,
        #[SerializedName('active')]
        #[ApiProperty(
            description: 'Whether the body exists today, which is what `abrogationDate` says read against the '
                . 'calendar. Stated separately so a consumer does not have to compare dates to find out.',
        )]
        public bool $active,
    ) {
    }
}
