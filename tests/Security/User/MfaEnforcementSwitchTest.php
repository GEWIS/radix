<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use App\Security\User\MfaEnforcementSwitch;
use PHPUnit\Framework\TestCase;

/**
 * The flag is process-wide because an entity cannot inject it, and its default is the production-safe one.
 */
final class MfaEnforcementSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        MfaEnforcementSwitch::setEnabled(true);
    }

    protected function tearDown(): void
    {
        MfaEnforcementSwitch::setEnabled(true);
    }

    public function testItIsEnforcingWhenSet(): void
    {
        self::assertTrue(MfaEnforcementSwitch::isEnabled());
    }

    public function testItCanBeTurnedOffAndOnAgain(): void
    {
        MfaEnforcementSwitch::setEnabled(false);
        self::assertFalse(MfaEnforcementSwitch::isEnabled());

        MfaEnforcementSwitch::setEnabled(true);
        self::assertTrue(MfaEnforcementSwitch::isEnabled());
    }
}
