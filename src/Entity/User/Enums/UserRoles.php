<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Enum for all the possible roles that exist within the ACL.
 */
enum UserRoles: string implements TranslatableInterface
{
    case Guest = 'PUBLIC_ACCESS';
    case TueGuest = 'ROLE_TUE_GUEST';

    case ApiUser = 'ROLE_API_USER';
    case Company = 'ROLE_COMPANY_USER';
    case User = 'ROLE_USER';
    case Member = 'ROLE_MEMBER';
    case Graduate = 'ROLE_GRADUATE';
    case ActiveMember = 'ROLE_ACTIVE_MEMBER';
    case CompanyAdmin = 'ROLE_COMPANY_ADMIN';
    case Board = 'ROLE_BOARD';
    case Admin = 'ROLE_ADMIN';

    /**
     * The register's own administrator, which is not the website's.
     *
     * Granted for as long as somebody is the serving secretary rather than written down against an account, and taken
     * away again the day they are relieved. {@see \App\Entity\User\User::getRoles()} is where that is decided.
     */
    case DatabaseAdmin = 'ROLE_DATABASE_ADMIN';

    /**
     * What a secretary keeps between being relieved and being discharged: the register stays readable, so the
     * questions their annual report raises can still be answered, but nothing can be changed with it.
     */
    case DatabaseReadOnly = 'ROLE_DATABASE_READ_ONLY';

    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::Guest => new TranslatableMessage('Guest'),
            self::TueGuest => new TranslatableMessage('TU/e Guest'),
            self::ApiUser => new TranslatableMessage('API User'),
            self::Company => new TranslatableMessage('Company'),
            self::User => new TranslatableMessage('User'),
            self::Member => new TranslatableMessage('Member'),
            self::Graduate => new TranslatableMessage('Graduate'),
            self::ActiveMember => new TranslatableMessage('Active Member'),
            self::CompanyAdmin => new TranslatableMessage('Company Admin'),
            self::Board => new TranslatableMessage('Board'),
            self::Admin => new TranslatableMessage('Admin'),
            self::DatabaseAdmin => new TranslatableMessage('Register Admin'),
            self::DatabaseReadOnly => new TranslatableMessage('Register Read-only'),
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->label()->trans(
            $translator,
            $locale,
        );
    }

    /**
     * @return array<string, string>
     */
    public static function getFormValueOptions(TranslatorInterface $translator): array
    {
        return [
            self::Guest->value => $translator->trans('Guest - Anyone who can access the website'),
            self::TueGuest->value => $translator->trans(
                'TU/e Guest - Anyone who can access the website from within the TU/e',
            ),
            self::Company->value => $translator->trans('Company - Authenticated company representative'),
            self::User->value => $translator->trans('User - Authenticated member or graduate'),
            self::Member->value => $translator->trans('Member - Authenticated member'),
            self::Graduate->value => $translator->trans('Graduate - Authenticated graduate'),
            self::ActiveMember->value => $translator->trans('Active Member - Authenticated member and in an organ'),
            self::CompanyAdmin->value => $translator->trans('Company Admin - C4 members'),
            self::Board->value => $translator->trans('Admin - Board'),
            self::Admin->value => $translator->trans('Admin - Tom'),
        ];
    }
}
