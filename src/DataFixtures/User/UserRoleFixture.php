<?php

declare(strict_types=1);

namespace App\DataFixtures\User;

use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Entity\User\UserRole;
use DateInterval;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Override;

use function range;

class UserRoleFixture extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    #[Override]
    public function load(ObjectManager $manager): void
    {
        // The three administrators differ in what they administer, so the difference between the website's rights
        // and the register's is visible without editing a row: one holds both, one only the website, one only the
        // register. The register's role is ordinarily worked out from who the secretary is; granted here it says
        // somebody has been given those rights without holding that office.
        foreach (
            [
                8000 => [
                    UserRoles::Admin,
                    UserRoles::DatabaseAdmin,
                ],
                8001 => [UserRoles::Admin],
                8002 => [UserRoles::DatabaseAdmin],
            ] as $lidnr => $roles
        ) {
            foreach ($roles as $role) {
                $userRole = new UserRole();
                $userRole->setRole($role);
                $userRole->setExpiration(new DateTime()->add(new DateInterval('P10Y')));
                $userRole->setLidnr($this->getReference('user-' . $lidnr, User::class));

                $manager->persist($userRole);
            }
        }

        // Company admins (8003 - 8004)
        foreach (
            range(
                8003,
                8004,
            ) as $lidnr
        ) {
            $companyAdminRole = new UserRole();
            $companyAdminRole->setRole(UserRoles::CompanyAdmin);
            $companyAdminRole->setExpiration(new DateTime()->add(new DateInterval('P10Y')));
            $companyAdminRole->setLidnr($this->getReference('user-' . $lidnr, User::class));

            $manager->persist($companyAdminRole);
        }

        $manager->flush();
    }

    /**
     * @return array<array-key, class-string<Fixture>>
     */
    #[Override]
    public function getDependencies(): array
    {
        return [
            UserFixture::class,
        ];
    }

    /**
     * @return string[]
     */
    #[Override]
    public static function getGroups(): array
    {
        return ['web'];
    }
}
