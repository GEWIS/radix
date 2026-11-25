<?php

declare(strict_types=1);

namespace App\Tests\EventListener\User;

use App\EventListener\User\MfaEnforcementBootListener;
use App\Security\User\MfaEnforcementSwitch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The flag is pushed into the process-wide switch before the security stack resolves any user's roles.
 */
final class MfaEnforcementBootListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        MfaEnforcementSwitch::setEnabled(true);
    }

    public function testTheConfiguredValueIsPushedIntoTheSwitch(): void
    {
        (new MfaEnforcementBootListener(false))($this->event());

        self::assertFalse(MfaEnforcementSwitch::isEnabled());
    }

    public function testEnforcementCanBeTurnedOnAgain(): void
    {
        MfaEnforcementSwitch::setEnabled(false);

        (new MfaEnforcementBootListener(true))($this->event());

        self::assertTrue(MfaEnforcementSwitch::isEnabled());
    }

    private function event(): RequestEvent
    {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/en'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
