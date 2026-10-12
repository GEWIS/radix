<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an API principal's token is allowed to do. A case is an operation gate, a property gate on the member
 * payload, or a row filter. Backing values are stored against a principal and are never renamed.
 */
enum ApiPermissions: string implements TranslatableInterface
{
    case HealthR = 'health_read';
    case MembersR = 'members_read';
    case MembersActiveR = 'members_active_read';
    case MembersBirthdaysR = 'members_birthdays_read';
    case MembersPropertyKeyholder = 'members_read_keyholder';
    case MembersPropertyType = 'members_read_type';
    case MembersPropertyEmail = 'members_read_email';
    case MembersPropertyBirthDate = 'members_read_birthdate';
    case MembersPropertyAge16 = 'members_read_is16';
    case MembersPropertyAge18 = 'members_read_is18';
    case MembersPropertyAge21 = 'members_read_is21';
    case MembersDeleted = 'members_deleted';
    case OrgansMembershipR = 'organs_members_read';
    case OrganFunctionsListR = 'organs_functionslist_read';
    case BoardFunctionsListR = 'boards_functionslist_read';
    case BodiesR = 'bodies_read';
    case BodyMembersR = 'bodies_members_read';
    case BoardsR = 'boards_read';
    case KeyholdersR = 'keyholders_read';
    case MailingListsR = 'mailinglists_read';
    case MailingListMembersR = 'mailinglists_members_read';
    case ActivitiesR = 'activities_read';
    case PhotoAlbumsR = 'photos_albums_read';
    case PhotoOfTheWeekR = 'photos_potw_read';
    case All = '*';

    /**
     * The permission name, deferred so the caller decides on the locale (or takes the source string).
     */
    public function getName(): TranslatableMessage
    {
        return match ($this) {
            self::HealthR => new TranslatableMessage('Get API Health'),
            self::MembersR => new TranslatableMessage('Get all Members'),
            self::MembersActiveR => new TranslatableMessage(
                'Get active Members (members that are in one or more bodies)',
            ),
            self::MembersBirthdaysR => new TranslatableMessage('Get Members celebrating their birthday'),
            self::MembersPropertyKeyholder => new TranslatableMessage('Member¹ - Check if keyholder'),
            self::MembersPropertyType => new TranslatableMessage('Member¹ - Check membership type'),
            self::MembersPropertyEmail => new TranslatableMessage('Member¹ - Get email address'),
            self::MembersPropertyBirthDate => new TranslatableMessage('Member¹ - Get birthdate'),
            self::MembersPropertyAge16 => new TranslatableMessage('Member¹ - Check if has reached age 16'),
            self::MembersPropertyAge18 => new TranslatableMessage('Member¹ - Check if has reached age 18'),
            self::MembersPropertyAge21 => new TranslatableMessage('Member¹ - Check if has reached age 21'),
            self::MembersDeleted => new TranslatableMessage('Member¹ - Allow operations on `deleted\' members'),
            self::OrgansMembershipR => new TranslatableMessage('Member¹ - Read body membership (per user/body)'),
            self::OrganFunctionsListR => new TranslatableMessage('Bodies - List functions and translations'),
            self::BoardFunctionsListR => new TranslatableMessage('Boards - List functions and translations'),
            self::BodiesR => new TranslatableMessage('Bodies - List bodies and their details'),
            self::BodyMembersR => new TranslatableMessage('Bodies - List the members installed in a body'),
            self::BoardsR => new TranslatableMessage('Boards - List board installations'),
            self::KeyholdersR => new TranslatableMessage('Keys - List keyholders'),
            self::MailingListsR => new TranslatableMessage('Mailing lists - List mailing lists'),
            self::MailingListMembersR => new TranslatableMessage(
                'Mailing lists - List subscribers of a mailing list, including their email addresses',
            ),
            self::ActivitiesR => new TranslatableMessage('Activities - List activities'),
            self::PhotoAlbumsR => new TranslatableMessage('Photos - Read albums and their photos'),
            self::PhotoOfTheWeekR => new TranslatableMessage('Photos - Read the Photo of the Week'),
            self::All => new TranslatableMessage('All API permissions'),
        };
    }

    public function getGroup(): ApiPermissionGroups
    {
        return match ($this) {
            self::HealthR => ApiPermissionGroups::Basic,
            self::MembersR,
            self::MembersActiveR,
            self::MembersBirthdaysR => ApiPermissionGroups::Members,
            self::MembersPropertyKeyholder,
            self::MembersPropertyType,
            self::MembersPropertyEmail,
            self::MembersPropertyBirthDate,
            self::MembersPropertyAge16,
            self::MembersPropertyAge18,
            self::MembersPropertyAge21,
            self::MembersDeleted,
            self::OrgansMembershipR => ApiPermissionGroups::MemberProperties,
            self::OrganFunctionsListR,
            self::BodiesR,
            self::BodyMembersR => ApiPermissionGroups::Bodies,
            self::BoardFunctionsListR,
            self::BoardsR => ApiPermissionGroups::Boards,
            self::KeyholdersR => ApiPermissionGroups::Keys,
            self::MailingListsR,
            self::MailingListMembersR => ApiPermissionGroups::MailingLists,
            self::ActivitiesR => ApiPermissionGroups::Activities,
            self::PhotoAlbumsR,
            self::PhotoOfTheWeekR => ApiPermissionGroups::Photos,
            self::All => ApiPermissionGroups::Everything,
        };
    }

    public function isMemberProperty(): bool
    {
        return ApiPermissionGroups::MemberProperties === $this->getGroup();
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->getName()->trans(
            $translator,
            $locale,
        );
    }

    public function getString(): string
    {
        return $this->value;
    }

    /**
     * @return array<string,string>
     */
    public static function toArray(TranslatorInterface $translator): array
    {
        $response = [];
        foreach (self::cases() as $case) {
            $response[$case->value] = $case->trans($translator);
        }

        return $response;
    }
}
