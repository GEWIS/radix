<?php

declare(strict_types=1);

namespace App\Tests\Entity\User\Enums;

use App\Entity\User\Enums\SecurityEventCategory;
use App\Entity\User\Enums\SecurityEventType;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

use function count;
use function in_array;
use function preg_match;

final class SecurityEventTypeTest extends TestCase
{
    /**
     * The levels a security record may be written at. Anything else would be a level the handler in
     * `config/packages/monolog.yaml` is not configured to keep, which is how an event ends up recorded nowhere.
     */
    private const array LEVELS = [
        LogLevel::INFO,
        LogLevel::NOTICE,
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    /**
     * Every case answers all three questions. A `match` without a default arm throws on a case somebody added and
     * did not finish, and this is what turns that into a failing test rather than a 500 on the day it first happens.
     */
    public function testEveryEventIsFullyDescribed(): void
    {
        foreach (SecurityEventType::cases() as $event) {
            self::assertContains(
                $event->category(),
                SecurityEventCategory::cases(),
            );
            self::assertNotSame(
                '',
                $event->label()->getMessage(),
            );

            self::assertTrue(
                in_array(
                    $event->level(),
                    self::LEVELS,
                    true,
                ),
                $event->value . ' is written at a level the security handler does not keep.',
            );
        }
    }

    /**
     * The values are written to the database and to half a year of log files. A duplicate would merge two events into
     * one; a rename would split one into two, which is worse, because nothing would say it had happened.
     */
    public function testValuesAreUniqueAndStable(): void
    {
        $values = [];

        foreach (SecurityEventType::cases() as $event) {
            self::assertSame(
                1,
                preg_match(
                    '/^[a-z][a-z0-9_]*$/',
                    $event->value,
                ),
                $event->value . ' is not a stable, lower-case identifier.',
            );

            $values[$event->value] = true;
        }

        self::assertCount(
            count(SecurityEventType::cases()),
            $values,
        );
    }

    /**
     * Only the resumption of a session goes to the file alone. It happens on every arrival from another site and
     * would be most of the table inside a week; everything else is worth a row.
     */
    public function testOnlyResumedSessionsAreLeftOutOfTheTable(): void
    {
        foreach (SecurityEventType::cases() as $event) {
            self::assertSame(
                SecurityEventType::SessionResumed !== $event,
                $event->isRecorded(),
                $event->value . ' is recorded differently than expected.',
            );
        }
    }

    /**
     * A token presented twice signs an account out on every device. It is the most severe thing this application
     * does on its own, and if it is not recorded, nobody finds out why somebody was signed out everywhere.
     */
    public function testAReplayedTokenIsCritical(): void
    {
        self::assertSame(
            LogLevel::CRITICAL,
            SecurityEventType::RememberMeTokenReplayed->level(),
        );
    }
}
