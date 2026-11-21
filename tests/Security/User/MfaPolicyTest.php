<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Entity\User\UserRole;
use App\Security\User\MfaPolicy;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * Who is in scope for enforced second-factor authentication: the board, and an account holding an active admin role.
 */
final class MfaPolicyTest extends TestCase
{
    public function testNothingIsRequiredWhileEnforcementIsOff(): void
    {
        self::assertFalse(
            new MfaPolicy(false)->isRequiredFor($this->user(board: true)),
        );
    }

    public function testABoardMemberIsInScope(): void
    {
        self::assertTrue(
            new MfaPolicy(true)->isRequiredFor($this->user(board: true)),
        );
    }

    public function testAnActiveAdminRoleIsInScope(): void
    {
        self::assertTrue(
            new MfaPolicy(true)->isRequiredFor($this->user(roles: [
                $this->role(
                    UserRoles::Admin,
                    active: true,
                ),
            ])),
        );
    }

    public function testAnInactiveAdminRoleIsNotInScope(): void
    {
        self::assertFalse(
            new MfaPolicy(true)->isRequiredFor($this->user(roles: [
                $this->role(
                    UserRoles::Admin,
                    active: false,
                ),
            ])),
        );
    }

    public function testAnotherRoleIsNotInScope(): void
    {
        self::assertFalse(
            new MfaPolicy(true)->isRequiredFor($this->user(roles: [
                $this->role(
                    UserRoles::User,
                    active: true,
                ),
            ])),
        );
    }

    public function testAnAccountWithASecondFactorHasEnrolled(): void
    {
        $user = $this->user();
        $user->totpSecret = 'a-secret';

        self::assertTrue(new MfaPolicy(true)->hasEnrolled($user));
    }

    public function testAnAccountWithoutASecondFactorHasNotEnrolled(): void
    {
        self::assertFalse(new MfaPolicy(true)->hasEnrolled($this->user()));
    }

    /**
     * @param list<UserRole> $roles
     */
    private function user(
        bool $board = false,
        array $roles = [],
    ): User {
        $member = self::createStub(Member::class);
        $member->method('isBoardMember')->willReturn($board);

        $user = self::createStub(User::class);
        $user->member = $member;
        $user->method('getRoleEntities')->willReturn(new ArrayCollection($roles));

        return $user;
    }

    private function role(
        UserRoles $role,
        bool $active,
    ): UserRole {
        $userRole = self::createStub(UserRole::class);
        $userRole->role = $role;
        $userRole->method('isActive')->willReturn($active);

        return $userRole;
    }
}
