<?php

declare(strict_types=1);

namespace App\Tests\Util\Activity;

use App\Util\Activity\SignupAdminWindow;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The page-level gate and the security boundary share these windows, so they cannot disagree about who may look at or
 * change a sign-up.
 */
final class SignupAdminWindowTest extends TestCase
{
    public function testOrganisersMayViewUntilAWeekAfterTheActivity(): void
    {
        self::assertTrue(SignupAdminWindow::canView(new DateTimeImmutable('-6 days'), false));
        self::assertFalse(SignupAdminWindow::canView(new DateTimeImmutable('-8 days'), false));
    }

    public function testTheBoardIsNeverTimeLimited(): void
    {
        self::assertTrue(SignupAdminWindow::canView(new DateTimeImmutable('-1 year'), true));
    }

    public function testPresenceMayBeMarkedFromHalfAnHourBeforeUntilADayAfter(): void
    {
        self::assertTrue(SignupAdminWindow::canMarkPresence(
            new DateTimeImmutable('-1 hour'),
            new DateTimeImmutable('+1 hour'),
        ));
        self::assertFalse(SignupAdminWindow::canMarkPresence(
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable('+2 hours'),
        ));
        self::assertFalse(SignupAdminWindow::canMarkPresence(
            new DateTimeImmutable('-3 days'),
            new DateTimeImmutable('-2 days'),
        ));
    }

    public function testAdmissionMayBeChangedUntilADayAfterTheActivity(): void
    {
        self::assertTrue(SignupAdminWindow::canChangeAdmission(new DateTimeImmutable('+1 hour')));
        self::assertFalse(SignupAdminWindow::canChangeAdmission(new DateTimeImmutable('-2 days')));
    }
}
