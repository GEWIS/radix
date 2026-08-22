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
use App\State\Decision\MemberBodyProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'MemberBody',
    description: 'A member installed in a body, seen from the member.',
    operations: [
        new GetCollection(
            uriTemplate: '/members/{lidnr}/bodies',
            uriVariables: [
                'lidnr' => new Link(
                    fromClass: Member::class,
                    identifiers: ['lidnr'],
                    parameterName: 'lidnr',
                ),
            ],
            requirements: ['lidnr' => '\d+'],
            openapi: new OpenApiOperation(
                responses: [
                    404 => new OpenApiResponse('No member is stored under that membership number.'),
                ],
                summary: 'Get the bodies a member is installed in',
                description: 'Every body the member currently sits in, paged and ordered by abbreviation. Set '
                    . '`includeDischarged` to also get the installations that have ended; they are told apart by '
                    . '`current`. A membership number no member is stored under is a missing resource (404) rather '
                    . 'than an empty page, so a consumer can tell a member who sits in nothing from one that does '
                    . 'not exist.',
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
    provider: MemberBodyProvider::class,
)]
final readonly class MemberBody
{
    public const string OPERATION_COLLECTION = 'api_member_bodies';

    public function __construct(
        #[SerializedName('body')]
        #[ApiProperty(
            description: 'The body the member is installed in, named by the id `/bodies/{id}` answers to.',
            openapiContext: [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'abbreviation' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                ],
            ],
        )]
        public BodySummary $body,
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
