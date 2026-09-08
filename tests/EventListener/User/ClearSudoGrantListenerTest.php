<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\ClearSudoGrantListener;
use App\Tests\Support\BuildsSudoMode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class ClearSudoGrantListenerTest extends TestCase
{
    use BuildsSudoMode;

    public function testSigningOutDropsTheGrant(): void
    {
        $tokenStorage = $this->tokenStorage('8000');
        $sudoMode = $this->sudoMode(
            $this->session(),
            $tokenStorage,
        );
        $sudoMode->grant();

        new ClearSudoGrantListener($sudoMode)(new LogoutEvent(
            new Request(),
            $tokenStorage->getToken(),
        ));

        self::assertFalse($sudoMode->isActive());
    }
}
