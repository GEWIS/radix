<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\RequireSudoToImpersonateListener;
use App\Security\User\SudoVoter;
use App\Tests\Support\BuildsSudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

final class RequireSudoToImpersonateListenerTest extends TestCase
{
    use BuildsSudoMode;

    public function testAnAdministratorWhoConfirmedMayImpersonate(): void
    {
        $tokenStorage = $this->tokenStorage('8000');
        $sudoMode = $this->sudoMode(
            $this->session(),
            $tokenStorage,
        );
        $sudoMode->grant();

        $listener = new RequireSudoToImpersonateListener($sudoMode);
        $listener($this->switchEvent($tokenStorage));

        // Getting here without an exception is the assertion.
        self::assertTrue($sudoMode->isActive());
    }

    public function testAnAdministratorWhoDidNotConfirmIsSentToTheSudoPrompt(): void
    {
        $tokenStorage = $this->tokenStorage('8000');
        $sudoMode = $this->sudoMode(
            $this->session(),
            $tokenStorage,
        );

        $listener = new RequireSudoToImpersonateListener($sudoMode);

        try {
            $listener($this->switchEvent($tokenStorage));
        } catch (AccessDeniedException $e) {
            self::assertSame(
                [SudoVoter::ATTRIBUTE],
                $e->getAttributes(),
            );

            return;
        }

        self::fail('Impersonating without a grant should have been refused.');
    }

    /**
     * Leaving an impersonation carries the original token rather than a switched one. Somebody whose grant ran out
     * while impersonating has to be able to get back to their own account.
     */
    public function testLeavingAnImpersonationIsNotRefused(): void
    {
        $tokenStorage = $this->tokenStorage('8000');
        $sudoMode = $this->sudoMode(
            $this->session(),
            $tokenStorage,
        );

        $listener = new RequireSudoToImpersonateListener($sudoMode);
        $listener(new SwitchUserEvent(
            new Request(),
            new InMemoryUser(
                '8000',
                null,
            ),
            $tokenStorage->getToken(),
        ));

        self::assertFalse($sudoMode->isActive());
    }

    private function switchEvent(TokenStorageInterface $tokenStorage): SwitchUserEvent
    {
        $administrator = $tokenStorage->getToken();
        self::assertNotNull($administrator);

        $target = new InMemoryUser(
            '8001',
            null,
        );

        return new SwitchUserEvent(
            new Request(),
            $target,
            new SwitchUserToken(
                $target,
                'main',
                ['ROLE_USER'],
                $administrator,
            ),
        );
    }
}
