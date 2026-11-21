<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Repository\Application\MaintenanceWindowRepository;
use App\Repository\Career\CompanyPackageRepository;
use App\Security\User\UserChecker;
use App\Service\Application\MaintenanceStatusProvider;
use App\Service\User\CompanyUserAccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A member who may no longer sign in is refused with the same blanket sentence, so an attacker probing the form learns
 * nothing about which of the several reasons applies.
 */
final class UserCheckerMembershipTest extends TestCase
{
    #[DataProvider('refusals')]
    public function testAMemberWhoMayNotSignInIsRefused(
        bool $deleted,
        bool $hidden,
        bool $expired,
        ?string $email,
    ): void {
        $this->expectException(CustomUserMessageAccountStatusException::class);

        $this->checker()->checkPreAuth($this->user($deleted, $hidden, $expired, $email));
    }

    /**
     * @return iterable<string, array{bool, bool, bool, ?string}>
     */
    public static function refusals(): iterable
    {
        yield 'deleted' => [
            true,
            false,
            false,
            'member@gewis.nl',
        ];

        yield 'hidden' => [
            false,
            true,
            false,
            'member@gewis.nl',
        ];

        yield 'expired' => [
            false,
            false,
            true,
            'member@gewis.nl',
        ];

        yield 'no email' => [
            false,
            false,
            false,
            null,
        ];
    }

    public function testAMemberInGoodStandingMaySignIn(): void
    {
        $this->expectNotToPerformAssertions();

        $this->checker()->checkPreAuth($this->user(false, false, false, 'member@gewis.nl'));
    }

    /**
     * Anything that is neither a member nor a representative is not this checker's to refuse.
     */
    public function testAnotherKindOfUserIsLeftAlone(): void
    {
        $this->expectNotToPerformAssertions();

        $this->checker()->checkPreAuth(new InMemoryUser('someone', null));
    }

    private function checker(): UserChecker
    {
        $windows = self::createStub(MaintenanceWindowRepository::class);
        $windows->method('findActiveAt')->willReturn(null);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $roleHierarchy = self::createStub(RoleHierarchyInterface::class);
        $roleHierarchy->method('getReachableRoleNames')->willReturn(['ROLE_USER']);

        $packages = self::createStub(CompanyPackageRepository::class);
        $packages->method('hasNonExpiredPackage')->willReturn(true);

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new UserChecker(
            $translator,
            new MaintenanceStatusProvider(
                $windows,
                $requestStack,
            ),
            $roleHierarchy,
            new CompanyUserAccessPolicy($packages),
        );
    }

    private function user(
        bool $deleted,
        bool $hidden,
        bool $expired,
        ?string $email,
    ): User {
        $member = self::createStub(Member::class);
        $member->deleted = $deleted;
        $member->hidden = $hidden;
        $member->email = $email;
        $member->method('isExpired')->willReturn($expired);

        $user = self::createStub(User::class);
        $user->member = $member;

        return $user;
    }
}
