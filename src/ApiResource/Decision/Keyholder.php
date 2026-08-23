<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Decision\KeyholderProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'Keyholder',
    description: 'A member holding a key to the association\'s rooms. Currently valid grants by default; '
        . '`includeExpired` asks for the ones that have run out or been withdrawn.',
    operations: [
        new GetCollection(
            uriTemplate: '/keyholders',
            openapi: new OpenApiOperation(
                summary: 'Get keyholders',
                description: 'Every member who currently holds a key to the association\'s rooms, paged. Set '
                    . '`includeExpired` to also get the grantings that have expired or been withdrawn; they are '
                    . 'told apart by `current`.',
                parameters: [
                    new Parameter(
                        name: 'includeExpired',
                        in: 'query',
                        description: 'Also list the grantings that have expired or been withdrawn; they are told '
                            . 'apart by `current`. A value that is not recognisably true is read as false '
                            . 'rather than refused.',
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::KeyholdersR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::KeyholdersR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_COLLECTION,
        ),
    ],
    provider: KeyholderProvider::class,
)]
final readonly class Keyholder
{
    public const string OPERATION_COLLECTION = 'api_keyholders';

    public function __construct(
        #[SerializedName('lidnr')]
        #[ApiProperty(description: 'Membership number of the keyholder.')]
        public int $lidnr,
        #[SerializedName('full_name')]
        public string $fullName,
        #[SerializedName('expirationDate')]
        #[ApiProperty(description: 'When the granting expires, in the `Y-m-d\TH:i:sP` format.')]
        public string $expirationDate,
        #[SerializedName('withdrawnDate')]
        #[ApiProperty(
            description: 'When the key was handed back, in the `Y-m-d\TH:i:sP` format, or null if it never was.',
        )]
        public ?string $withdrawnDate,
        #[SerializedName('current')]
        #[ApiProperty(description: 'Whether the granting is in force today.')]
        public bool $current,
    ) {
    }
}
