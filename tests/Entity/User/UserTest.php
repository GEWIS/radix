<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Member;
use App\Entity\Decision\OrganMember;
use App\Entity\User\Enums\ColourVision;
use App\Entity\User\Enums\PhotoVisibility;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Entity\User\UserRole;
use App\Entity\User\UserSettings;
use App\Security\User\MfaEnforcementSwitch;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function in_array;

/**
 * The roles an account carries: the one its membership gives it, the ones written down against it, the board and
 * secretarial seats that grant the register's rights while they run, and what is withheld from somebody who has not
 * enrolled their second factor.
 */
#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Process-wide and only assigned by the boot listener on a dispatched request; a test that reads roles without
        // one reads whatever the last request left behind.
        MfaEnforcementSwitch::setEnabled(false);
    }

    #[Override]
    protected function tearDown(): void
    {
        MfaEnforcementSwitch::setEnabled(false);

        parent::tearDown();
    }

    public function testTheMembershipDecidesTheBaseRole(): void
    {
        $graduate = $this->user(MembershipTypes::Graduate);
        self::assertContains(
            UserRoles::Graduate->value,
            $graduate->getRoles(),
        );

        $ordinary = $this->user(MembershipTypes::Ordinary);
        self::assertContains(
            UserRoles::Member->value,
            $ordinary->getRoles(),
        );

        $inAnOrgan = $this->user(MembershipTypes::Ordinary);
        $seat = new OrganMember();
        $seat->function = InstallationFunctions::Member;
        $seat->installDate = new DateTimeImmutable('-1 month');
        $inAnOrgan->member->getOrganInstallations()->add($seat);

        self::assertContains(
            UserRoles::ActiveMember->value,
            $inAnOrgan->getRoles(),
        );
        self::assertNotContains(
            UserRoles::Member->value,
            $inAnOrgan->getRoles(),
        );
    }

    public function testEveryAccountMayActForItsOwnMember(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);

        self::assertContains(
            'ROLE_USER_4242',
            $user->getRoles(),
        );
    }

    public function testAWrittenDownRoleIsOnlyGrantedWhileItRuns(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);

        $role = new UserRole();
        $role->role = UserRoles::Admin;
        $role->setExpiration(new DateTimeImmutable('+1 month'));
        $user->getRoleEntities()->add($role);

        self::assertContains(
            UserRoles::Admin->value,
            $user->getRoles(),
        );

        $role->setExpiration(new DateTimeImmutable('-1 day'));

        self::assertNotContains(
            UserRoles::Admin->value,
            $user->getRoles(),
            'A role that has run out is no longer granted.',
        );
    }

    public function testACurrentBoardSeatCarriesTheBoardAndTheRegister(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);
        $seat = new BoardMember();
        $seat->function = BoardFunctions::Chair;
        $seat->installDate = new DateTimeImmutable('-1 month');
        $user->member->getBoardInstallations()->add($seat);

        self::assertContains(
            UserRoles::Board->value,
            $user->getRoles(),
        );
        self::assertContains(
            UserRoles::DatabaseMemberReadOnly->value,
            $user->getRoles(),
        );

        // The seat they were relieved of grants nothing.
        $seat->releaseDate = new DateTimeImmutable('-1 day');

        self::assertNotContains(
            UserRoles::Board->value,
            $user->getRoles(),
        );
    }

    public function testTheSecretaryAdministersTheRegisterAndARelievedOneOnlyReadsIt(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);
        $seat = new BoardMember();
        $seat->function = BoardFunctions::Secretary;
        $seat->installDate = new DateTimeImmutable('-1 month');
        $user->member->getBoardInstallations()->add($seat);

        self::assertContains(
            UserRoles::DatabaseAdmin->value,
            $user->getRoles(),
        );
        self::assertNotContains(
            UserRoles::DatabaseReadOnly->value,
            $user->getRoles(),
        );

        $seat->releaseDate = new DateTimeImmutable('-1 day');

        self::assertNotContains(
            UserRoles::DatabaseAdmin->value,
            $user->getRoles(),
        );
        self::assertContains(
            UserRoles::DatabaseReadOnly->value,
            $user->getRoles(),
            'A relieved secretary whose year is not yet discharged can still read the register.',
        );

        $seat->dischargeDate = new DateTimeImmutable('-1 day');

        self::assertNotContains(
            UserRoles::DatabaseReadOnly->value,
            $user->getRoles(),
        );
    }

    public function testSomethingWorthProtectingIsWithheldUntilASecondFactorIsEnrolled(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);
        $role = new UserRole();
        $role->role = UserRoles::Admin;
        $role->setExpiration(new DateTimeImmutable('+1 month'));
        $user->getRoleEntities()->add($role);

        MfaEnforcementSwitch::setEnabled(true);

        self::assertNotContains(
            UserRoles::Admin->value,
            $user->getRoles(),
        );
        // What does not open anything protected stays, so the account is still a member with a name.
        self::assertContains(
            UserRoles::Member->value,
            $user->getRoles(),
        );

        $user->totpSecret = 'SECRET';

        self::assertContains(
            UserRoles::Admin->value,
            $user->getRoles(),
        );
    }

    public function testAMemberWithoutAnythingProtectedKeepsTheirRolesUnderEnforcement(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);

        MfaEnforcementSwitch::setEnabled(true);

        self::assertTrue(in_array(
            UserRoles::Member->value,
            $user->getRoles(),
            true,
        ));
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testTheSecondFactorIsOnlyOfferedOnceASecretIsSet(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);

        self::assertNull($user->getTotpAuthenticationConfiguration());
        self::assertFalse($user->isTotpAuthenticationEnabled());

        $user->totpSecret = 'JBSWY3DPEHPK3PXP';

        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertNotNull($user->getTotpAuthenticationConfiguration());
        self::assertSame(
            '4242',
            $user->getTotpAuthenticationUsername(),
        );
    }

    public function testAnAccountWithoutASettingsRowReadsTheDocumentedDefaults(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);

        self::assertNull($user->settings);
        self::assertFalse($user->hasDisabledCosmetics());
        self::assertFalse($user->hasHiddenYearOfBirth());
        self::assertSame(
            ColourVision::Default,
            $user->getColourVision(),
        );
        self::assertSame(
            PhotoVisibility::HideSelected,
            $user->getPhotoVisibility(),
        );
    }

    public function testTheSettingsDefaultsAreWhatAnUnsetAccountHas(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);
        $user->settings = new UserSettings($user);

        self::assertFalse($user->hasDisabledCosmetics());
        self::assertFalse($user->hasHiddenYearOfBirth());
        self::assertSame(
            ColourVision::Default,
            $user->getColourVision(),
        );
        self::assertSame(
            PhotoVisibility::HideSelected,
            $user->getPhotoVisibility(),
        );

        $user->settings->disableCosmetics = true;
        $user->settings->hideYearOfBirth = true;
        $user->settings->colourVision = ColourVision::RedGreen;
        $user->settings->photoVisibility = PhotoVisibility::HideAll;

        self::assertTrue($user->hasDisabledCosmetics());
        self::assertTrue($user->hasHiddenYearOfBirth());
        self::assertSame(
            ColourVision::RedGreen,
            $user->getColourVision(),
        );
        self::assertSame(
            PhotoVisibility::HideAll,
            $user->getPhotoVisibility(),
        );
    }

    public function testTheDisplayNameIsTheMembersFullName(): void
    {
        self::assertSame(
            'Test Lid',
            $this->user(MembershipTypes::Ordinary)->getDisplayName(),
        );
    }

    public function testTheDataExportNamesTheRolesAndWhenThePasswordLastChanged(): void
    {
        $user = $this->user(MembershipTypes::Ordinary);
        $role = new UserRole();
        $role->role = UserRoles::Admin;
        $role->setExpiration(new DateTimeImmutable('2027-01-01 00:00'));
        $user->getRoleEntities()->add($role);
        $user->setPasswordChangedOn(new DateTimeImmutable('2026-01-01 12:00'));

        $export = $user->toGdprArray();

        self::assertSame(
            [
                [
                    'role' => UserRoles::Admin->value,
                    'expiration' => '2027-01-01T00:00:00+01:00',
                ],
            ],
            $export['roles'],
        );
        self::assertIsString($export['passwordChangedOn']);
        self::assertStringStartsWith(
            '2026-01-01T12:00:00',
            $export['passwordChangedOn'],
        );
        self::assertNull(
            $export['settings'],
            'An account that never stored a preference exports none.',
        );
    }

    private function user(MembershipTypes $type): User
    {
        $member = new Member();
        $member->lidnr = 4242;
        $member->initials = 'T.';
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Lid';
        $member->type = $type;
        $member->birth = new DateTimeImmutable('2000-01-01');
        $member->expiration = new DateTimeImmutable('+1 year');

        $user = new User();
        $user->lidnr = $member->lidnr;
        $user->member = $member;

        return $user;
    }
}
