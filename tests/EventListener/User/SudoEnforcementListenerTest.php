<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\SudoEnforcementListener;
use App\Security\User\SudoVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SudoEnforcementListenerTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function guardedPaths(): array
    {
        return [
            ['/en/admin'],
            ['/nl/admin'],
            ['/en/admin/users'],
            ['/nl/admin/members/1234/edit'],
            ['/en/user/settings'],
            ['/nl/user/settings/privacy'],
            ['/en/user/security'],
            ['/nl/user/security/mfa/backup-codes'],
            ['/en/company/security'],
            ['/nl/company/security/sessions/terminate-all'],
        ];
    }

    /**
     * @return list<array{string}>
     */
    public static function otherPaths(): array
    {
        return [
            ['/en/'],
            ['/en/company/vacancies'],
            ['/en/_components/UsersOverview'],
            ['/health'],
            ['/api/members'],
            // Somebody with no grant has to be able to go and get one.
            ['/en/user/sudo'],
            ['/nl/company/sudo'],
            ['/en/user/login'],
            ['/en/user/forgot-password'],
            ['/nl/company/reset-password'],
            // The navbar's cosmetics switch posts here from every page.
            ['/en/user/cosmetics'],
            // The segment has to be the area itself, not anything that starts the same way.
            ['/en/administration'],
            ['/en/adminfoo/bar'],
            ['/en/user/settingsfoo'],
            ['/de/admin'],
        ];
    }

    #[DataProvider('guardedPaths')]
    public function testAGuardedAreaIsRefusedWithoutAGrant(string $path): void
    {
        $event = $this->event($path);

        try {
            $this->listener(false)($event);
        } catch (AccessDeniedException $exception) {
            self::assertSame(
                [SudoVoter::ATTRIBUTE],
                $exception->getAttributes(),
            );

            return;
        }

        self::fail('A guarded area was reachable without a sudo grant.');
    }

    #[DataProvider('guardedPaths')]
    public function testAGrantLetsAGuardedAreaThrough(string $path): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener(true)($this->event($path));
    }

    #[DataProvider('otherPaths')]
    public function testEverythingElseIsLeftAlone(string $path): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener(false)($this->event($path));
    }

    /**
     * A fragment is not a request of its own; the main request it was rendered for was asked for the grant.
     */
    public function testASubRequestIsLeftAlone(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener(false)(
            $this->event(
                '/en/admin/users',
                HttpKernelInterface::SUB_REQUEST,
            ),
        );
    }

    private function listener(bool $granted): SudoEnforcementListener
    {
        $authorizationChecker = self::createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($granted);

        return new SudoEnforcementListener(
            $authorizationChecker,
            'en|nl',
        );
    }

    private function event(
        string $path,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path),
            $type,
        );
    }
}
