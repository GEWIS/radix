<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Decision\BoardMemberProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'BoardMember',
    description: 'A board installation. Current ones by default; `includeFormer` asks for the boards that came '
        . 'before.',
    operations: [
        new GetCollection(
            uriTemplate: '/boards',
            openapi: new OpenApiOperation(
                summary: 'Get board installations',
                description: 'Every member currently serving on the board, newest installation first, paged. Set '
                    . '`includeFormer` to also get the installations that have been released or discharged; they '
                    . 'are told apart by `current`.',
                parameters: [
                    new Parameter(
                        name: 'includeFormer',
                        in: 'query',
                        description: 'Also list the installations that have been released or discharged; they are '
                            . 'told apart by `current`. A value that is not recognisably true is read as '
                            . 'false rather than refused.',
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::BoardsR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::BoardsR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::V5_0_0],
            name: self::OPERATION_COLLECTION,
        ),
    ],
    provider: BoardMemberProvider::class,
)]
final readonly class BoardMember
{
    public const string OPERATION_COLLECTION = 'api_boards';

    public function __construct(
        #[SerializedName('lidnr')]
        #[ApiProperty(description: 'Membership number of the board member.')]
        public int $lidnr,
        #[SerializedName('full_name')]
        public string $fullName,
        #[SerializedName('function')]
        #[ApiProperty(
            description: 'The function held. It is in Dutch because that is the language the decision was made in; '
                . '`/boardFunctions` lists every value there is with its translations.',
            openapiContext: ['$ref' => '#/components/schemas/BoardFunctionEnum'],
        )]
        public BoardFunctions $function,
        #[SerializedName('installDate')]
        #[ApiProperty(description: 'When the installation took effect, in the `Y-m-d\TH:i:sP` format.')]
        public string $installDate,
        #[SerializedName('releaseDate')]
        #[ApiProperty(
            description: 'When the member was relieved of the function, in the `Y-m-d\TH:i:sP` format, or null if '
                . 'they have not been.',
        )]
        public ?string $releaseDate,
        #[SerializedName('dischargeDate')]
        #[ApiProperty(
            description: 'When the board year was discharged, in the `Y-m-d\TH:i:sP` format, or null if it has not '
                . 'been.',
        )]
        public ?string $dischargeDate,
        #[SerializedName('current')]
        #[ApiProperty(description: 'Whether the member is serving in this function today.')]
        public bool $current,
    ) {
    }
}
