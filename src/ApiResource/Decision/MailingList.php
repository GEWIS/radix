<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Decision\MailingListProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'MailingList',
    description: 'A mailing list of the association. The name is its identifier, and it is case-sensitive.',
    operations: [
        new GetCollection(
            uriTemplate: '/mailing-lists',
            openapi: new OpenApiOperation(
                summary: 'Get mailing lists',
                description: 'Every mailing list the association keeps, paged and ordered by name.',
            ),
            security: "is_granted('" . ApiPermissions::MailingListsR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::MailingListsR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::V5_0_0],
            name: self::OPERATION_COLLECTION,
        ),
        new Get(
            uriTemplate: '/mailing-lists/{name}',
            requirements: ['name' => self::NAME_REQUIREMENT],
            openapi: new OpenApiOperation(
                summary: 'Get a mailing list',
                description: 'A single mailing list by the name that identifies it. A name no list is stored under '
                    . 'is a missing resource: 404.',
            ),
            security: "is_granted('" . ApiPermissions::MailingListsR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::MailingListsR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::V5_0_0],
            name: self::OPERATION_ITEM,
        ),
    ],
    provider: MailingListProvider::class,
)]
final readonly class MailingList
{
    public const string OPERATION_COLLECTION = 'api_mailing_lists';
    public const string OPERATION_ITEM = 'api_mailing_list';

    /** As wide as the register's own form allows, so every list it can store is reachable here. */
    public const string NAME_REQUIREMENT = '[^/]{2,64}';

    /**
     * @param array{en: string, nl: string} $description
     */
    public function __construct(
        #[SerializedName('name')]
        #[ApiProperty(
            description: 'The name the list is known by, on the servers that carry it as much as here.',
            identifier: true,
        )]
        public string $name,
        #[SerializedName('description')]
        #[ApiProperty(
            description: 'What the list is for, in English and in Dutch. The association is bilingual and neither '
                . 'description is a translation of the other, so both are always given.',
            openapiContext: [
                'type' => 'object',
                'properties' => [
                    'en' => ['type' => 'string'],
                    'nl' => ['type' => 'string'],
                ],
            ],
        )]
        public array $description,
    ) {
    }
}
