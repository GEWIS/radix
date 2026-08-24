<?php

declare(strict_types=1);

namespace App\Tests\Security\Database;

use App\Entity\User\Enums\UserRoles;
use App\Security\Database\RegisterNetworkChecker;
use App\Security\Database\RegisterNetworkRoleHierarchy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * The register's roles are taken away here, which is what `access_control`, `#[IsGranted]` and every `is_granted()`
 * ultimately ask through. What happens to the other roles matters as much: a secretary at home is still a member.
 */
final class RegisterNetworkRoleHierarchyTest extends TestCase
{
    private const array RANGES = ['131.155.68.0/24'];

    /** The hierarchy as `security.yaml` declares it, trimmed to what these assertions read. */
    private const array HIERARCHY = [
        'ROLE_MEMBER' => ['ROLE_USER'],
        'ROLE_ACTIVE_MEMBER' => ['ROLE_MEMBER'],
        'ROLE_DATABASE_ADMIN' => ['ROLE_DATABASE_READ_ONLY'],
    ];

    public function testTheRegistersRolesSurviveOnTheNetwork(): void
    {
        $reachable = $this->hierarchyFor('131.155.68.69')->getReachableRoleNames([
            UserRoles::DatabaseAdmin->value,
        ]);

        self::assertContains(
            UserRoles::DatabaseAdmin->value,
            $reachable,
        );
        self::assertContains(
            UserRoles::DatabaseReadOnly->value,
            $reachable,
        );
    }

    /** Both, not only the one handed to the account: read-only is reached through the administrator's. */
    public function testBothOfThemAreGoneOffTheNetwork(): void
    {
        $reachable = $this->hierarchyFor('8.8.8.8')->getReachableRoleNames([
            UserRoles::DatabaseAdmin->value,
        ]);

        self::assertNotContains(
            UserRoles::DatabaseAdmin->value,
            $reachable,
        );
        self::assertNotContains(
            UserRoles::DatabaseReadOnly->value,
            $reachable,
        );
    }

    /** A secretary reading this from home is still whatever else they are. */
    public function testEverythingElseIsUntouchedOffTheNetwork(): void
    {
        $reachable = $this->hierarchyFor('8.8.8.8')->getReachableRoleNames([
            UserRoles::ActiveMember->value,
            UserRoles::DatabaseAdmin->value,
        ]);

        self::assertContains(
            UserRoles::ActiveMember->value,
            $reachable,
        );
        self::assertContains(
            UserRoles::Member->value,
            $reachable,
        );
        self::assertContains(
            UserRoles::User->value,
            $reachable,
        );
        self::assertNotContains(
            UserRoles::DatabaseAdmin->value,
            $reachable,
        );
    }

    /** A list is filtered, not a name matched, so an inherited register role is withheld too. */
    public function testARoleInheritedFromElsewhereIsAlsoWithheld(): void
    {
        $hierarchy = new RegisterNetworkRoleHierarchy(
            new RoleHierarchy(['ROLE_SOMETHING_ELSE' => [UserRoles::DatabaseReadOnly->value]]),
            $this->checkerFor('8.8.8.8'),
        );

        $reachable = $hierarchy->getReachableRoleNames(['ROLE_SOMETHING_ELSE']);

        self::assertContains(
            'ROLE_SOMETHING_ELSE',
            $reachable,
        );
        self::assertNotContains(
            UserRoles::DatabaseReadOnly->value,
            $reachable,
        );
    }

    public function testAnUnconfiguredListLeavesEveryRoleAlone(): void
    {
        $hierarchy = new RegisterNetworkRoleHierarchy(
            new RoleHierarchy(self::HIERARCHY),
            new RegisterNetworkChecker(
                new RequestStack(),
                [],
            ),
        );

        self::assertContains(
            UserRoles::DatabaseAdmin->value,
            $hierarchy->getReachableRoleNames([UserRoles::DatabaseAdmin->value]),
        );
    }

    private function hierarchyFor(string $clientIp): RegisterNetworkRoleHierarchy
    {
        return new RegisterNetworkRoleHierarchy(
            new RoleHierarchy(self::HIERARCHY),
            $this->checkerFor($clientIp),
        );
    }

    private function checkerFor(string $clientIp): RegisterNetworkChecker
    {
        $stack = new RequestStack();
        $stack->push(Request::create(
            '/en/admin/members',
            server: ['REMOTE_ADDR' => $clientIp],
        ));

        return new RegisterNetworkChecker(
            $stack,
            self::RANGES,
        );
    }
}
