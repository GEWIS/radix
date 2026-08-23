<?php

declare(strict_types=1);

namespace App\ApiResource\Decision;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Api\ApiVersion;
use App\State\Decision\MailingListMemberProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ApiResource(
    shortName: 'MailingListMember',
    description: 'A subscription to a mailing list. A member subscribed under two addresses is two rows, which is '
        . 'why the membership number does not identify one.',
    operations: [
        new GetCollection(
            uriTemplate: '/mailing-lists/{name}/members',
            uriVariables: [
                'name' => new Link(
                    fromClass: MailingList::class,
                    identifiers: ['name'],
                    parameterName: 'name',
                ),
            ],
            requirements: ['name' => MailingList::NAME_REQUIREMENT],
            openapi: new OpenApiOperation(
                responses: [
                    404 => new OpenApiResponse('No mailing list is stored under that name.'),
                ],
                summary: 'Get the subscribers of a mailing list',
                description: 'Everyone currently subscribed to the named mailing list, paged and ordered by '
                    . 'membership number. A name no list is stored under is a missing resource (404) rather than an '
                    . 'empty page, so a consumer can tell a list nobody is on from a list that does not exist. '
                    . 'A subscription belonging to a deleted member is part of the page only for a principal holding '
                    . '`' . ApiPermissions::MembersDeleted->value . '`.',
            ),
            security: "is_granted('" . ApiPermissions::MailingListMembersR->value . "')",
            securityMessage: 'Permission `' . ApiPermissions::MailingListMembersR->value
                . '` is needed but is not currently held.',
            extraProperties: [ApiVersion::MINIMUM => ApiVersion::CURRENT],
            name: self::OPERATION_COLLECTION,
        ),
    ],
    provider: MailingListMemberProvider::class,
)]
final readonly class MailingListMember
{
    public const string OPERATION_COLLECTION = 'api_mailing_list_members';

    public function __construct(
        #[SerializedName('lidnr')]
        #[ApiProperty(description: 'Membership number of the subscriber.')]
        public int $lidnr,
        #[SerializedName('full_name')]
        public string $fullName,
        #[SerializedName('email')]
        #[ApiProperty(
            description: 'The address that is subscribed. A distribution group is not one without it, so `'
                . ApiPermissions::MailingListMembersR->value . '` hands it out on its own; in practice the register '
                . 'projects the address the membership carries, so granting this permission grants sight of it.',
        )]
        public string $email,
    ) {
    }
}
