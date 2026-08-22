<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Decision\BodyMemberProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'BodyMember',
    description: 'A member installed in a body, seen from the body. A member holding two functions in the same '
        . 'body is two rows.',
    operations: [
        new GetCollection(
            uriTemplate: '/bodies/{id}/members',
            uriVariables: [
                'id' => new Link(
                    fromClass: Body::class,
                    identifiers: ['id'],
                    parameterName: 'id',
                ),
            ],
            requirements: ['id' => Body::ID_REQUIREMENT],
            openapi: new OpenApiOperation(
                responses: [
                    404 => new OpenApiResponse('No body is stored under that identifier.'),
                ],
                summary: 'Get the members of a body',
                description: 'Everyone currently installed in the body, paged and ordered by membership number. Set '
                    . '`includeDischarged` to also get the installations that have ended; they are told apart by '
                    . '`current`. An id no body is stored under is a missing resource (404) rather than an empty '
                    . 'page, so a consumer syncing a group can tell a body nobody sits in from a body that does not '
                    . 'exist.',
                parameters: [
                    new Parameter(
                        name: 'includeDischarged',
                        in: 'query',
                        description: 'Also list the installations that have ended; they are told apart by '
                            . '`current`. A value that is not recognisably true is read as false rather than refused.',
                        schema: [
                            'type' => 'boolean',
                            'default' => false,
                        ],
                    ),
                ],
            ),
            security: "is_granted('" . ApiPermissions::BodyMembersR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::BodyMembersR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_COLLECTION,
        ),
    ],
    provider: BodyMemberProvider::class,
)]
final readonly class BodyMember
{
    public const string OPERATION_COLLECTION = 'api_body_members';

    public function __construct(
        #[SerializedName('lidnr')]
        #[ApiProperty(description: 'Membership number of the installed member.')]
        public int $lidnr,
        #[SerializedName('full_name')]
        public string $fullName,
        #[SerializedName('function')]
        #[ApiProperty(
            description: 'The function held in the body. It is in Dutch because that is the language the decision '
                . 'was made in and the register stores it verbatim; `/organFunctions` lists every value there is '
                . 'with its translations.',
        )]
        public string $function,
        #[SerializedName('installDate')]
        #[ApiProperty(description: 'When the installation took effect, in the `Y-m-d\TH:i:sP` format.')]
        public string $installDate,
        #[SerializedName('dischargeDate')]
        #[ApiProperty(
            description: 'When the member was discharged from the body, in the `Y-m-d\TH:i:sP` format, or null if no '
                . 'decision has discharged them. A date in the future is an installation that is still running.',
        )]
        public ?string $dischargeDate,
        #[SerializedName('current')]
        #[ApiProperty(
            description: 'Whether the member sits in the body today. Always true unless `includeDischarged` asked '
                . 'for the installations that have ended.',
        )]
        public bool $current,
    ) {
    }
}
