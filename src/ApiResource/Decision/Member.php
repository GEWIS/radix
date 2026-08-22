<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Decision\MemberProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'Member',
    description: 'A member of the association. Which fields are present depends on the permissions the token '
        . 'carries: a field it may not see is absent rather than null.',
    operations: [
        new GetCollection(
            uriTemplate: '/members',
            openapi: new OpenApiOperation(
                summary: 'Get members',
                description: 'Every member of the association, paged.',
                parameters: [
                    new Parameter(
                        name: 'includeOrgans',
                        in: 'query',
                        description: 'Include the bodies the member is currently installed in. Needs the `'
                            . ApiPermissions::OrgansMembershipR->value . '` permission; expensive, which is why it '
                            . 'is off by default. A value that is not recognisably true is read as false rather '
                            . 'than refused.',
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::MembersR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::MembersR->value
                . '` is needed but is not currently held.',
            name: self::OPERATION_COLLECTION,
        ),
        new GetCollection(
            uriTemplate: '/members/active',
            openapi: new OpenApiOperation(
                summary: 'Get active members',
                description: 'Every member currently installed in at least one body, paged.',
                parameters: [
                    new Parameter(
                        name: 'includeInactive',
                        in: 'query',
                        description: 'Also list the members whose only installation is as an inactive member of a '
                            . 'fraternity. A value that is not recognisably true is read as false rather than '
                            . 'refused.',
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::MembersActiveR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::MembersActiveR->value
                . '` is needed but is not currently held.',
            name: self::OPERATION_ACTIVE,
        ),
        new GetCollection(
            uriTemplate: '/members/birthdays',
            openapi: new OpenApiOperation(
                summary: 'Get members celebrating a birthday',
                description: 'Members whose birthday falls today, or within the next `days` days. Members who asked '
                    . 'for their birthday not to be published are never part of this list.',
                parameters: [
                    new Parameter(
                        name: 'days',
                        in: 'query',
                        description: 'How many days ahead to look. 0 is today. More than 31 is read as 31; a value '
                            . 'that is not a whole number is ignored rather than refused.',
                        schema: [
                            'type' => 'integer',
                            'default' => 0,
                            'minimum' => 0,
                            'maximum' => 31,
                        ],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::MembersBirthdaysR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::MembersBirthdaysR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_BIRTHDAYS,
        ),
        new Get(
            uriTemplate: '/members/{lidnr}',
            requirements: ['lidnr' => '\d+'],
            openapi: new OpenApiOperation(
                responses: [
                    204 => new OpenApiResponse(
                        description: 'No such member, or none the principal may see. The body is empty.',
                    ),
                ],
                summary: 'Get a member',
                description: 'A single member by membership number. A member that does not exist, or that the '
                    . 'principal may not see, is an empty dataset rather than a missing resource: 204, no body.',
            ),
            security: "is_granted('" . ApiPermissions::MembersR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::MembersR->value
                . '` is needed but is not currently held.',
            name: self::OPERATION_ITEM,
        ),
    ],
    provider: MemberProvider::class,
)]
final readonly class Member
{
    public const string OPERATION_COLLECTION = 'api_members';
    public const string OPERATION_ACTIVE = 'api_members_active';
    public const string OPERATION_BIRTHDAYS = 'api_members_birthdays';
    public const string OPERATION_ITEM = 'api_member';

    public const string GROUP_READ = 'member:read';

    public const string GROUP_ORGANS = 'member:read:organs';
    public const string GROUP_EMAIL = 'member:read:email';
    public const string GROUP_BIRTHDATE = 'member:read:birthdate';
    public const string GROUP_AGE_16 = 'member:read:is16';
    public const string GROUP_AGE_18 = 'member:read:is18';
    public const string GROUP_AGE_21 = 'member:read:is21';
    public const string GROUP_KEYHOLDER = 'member:read:keyholder';
    public const string GROUP_TYPE = 'member:read:type';

    /**
     * @param array<array-key, array<string, mixed>> $organs
     * @phpstan-param array<array-key, array{
     *     organ: array{id: int|null, abbreviation: string},
     *     function: string,
     *     installDate: string,
     *     dischargeDate: string|null,
     *     current: bool,
     * }> $organs
     */
    public function __construct(
        #[Groups([self::GROUP_READ])]
        #[SerializedName('lidnr')]
        #[ApiProperty(
            description: 'Membership number.',
            identifier: true,
        )]
        public int $lidnr,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('full_name')]
        public string $fullName,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('family_name')]
        public string $familyName,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('middle_name')]
        public string $middleName,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('initials')]
        public string $initials,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('given_name')]
        public string $givenName,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('generation')]
        #[ApiProperty(description: 'The calendar year in which the member joined.')]
        public int $generation,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('hidden')]
        public bool $hidden,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('deleted')]
        #[ApiProperty(
            description: 'Always false unless the principal holds `' . ApiPermissions::MembersDeleted->value . '`.',
        )]
        public bool $deleted,
        #[Groups([self::GROUP_READ])]
        #[SerializedName('expiration')]
        #[ApiProperty(description: 'When the membership expires, in the `Y-m-d\TH:i:sP` format.')]
        public string $expiration,
        #[Groups([self::GROUP_ORGANS])]
        #[SerializedName('organs')]
        #[ApiProperty(
            description: 'The bodies the member is currently installed in. Present only with `'
                . ApiPermissions::OrgansMembershipR->value . '`, and on `/members` only when `includeOrgans` asks '
                . 'for it.',
            openapiContext: [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'organ' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'abbreviation' => ['type' => 'string'],
                            ],
                        ],
                        'function' => ['type' => 'string'],
                        'installDate' => [
                            'type' => 'string',
                            'format' => 'date-time',
                        ],
                        'dischargeDate' => [
                            'type' => [
                                'string',
                                'null',
                            ],
                            'format' => 'date-time',
                        ],
                        'current' => ['type' => 'boolean'],
                    ],
                ],
            ],
        )]
        public array $organs = [],
        #[Groups([self::GROUP_EMAIL])]
        #[SerializedName('email')]
        public ?string $email = null,
        #[Groups([self::GROUP_BIRTHDATE])]
        #[SerializedName('birthdate')]
        #[ApiProperty(description: 'Date of birth, in the `Y-m-d\TH:i:sP` format.')]
        public ?string $birthdate = null,
        #[Groups([self::GROUP_AGE_16])]
        #[SerializedName('is_16_plus')]
        public ?bool $is16Plus = null,
        #[Groups([self::GROUP_AGE_18])]
        #[SerializedName('is_18_plus')]
        public ?bool $is18Plus = null,
        #[Groups([self::GROUP_AGE_21])]
        #[SerializedName('is_21_plus')]
        public ?bool $is21Plus = null,
        #[Groups([self::GROUP_KEYHOLDER])]
        #[SerializedName('keyholder')]
        #[ApiProperty(description: 'Whether the member currently holds a key to the association\'s rooms.')]
        public ?bool $keyholder = null,
        #[Groups([self::GROUP_TYPE])]
        #[SerializedName('membership_type')]
        #[ApiProperty(description: 'One of `ordinary`, `external`, `graduate` or `honorary`.')]
        public ?string $membershipType = null,
    ) {
    }
}
