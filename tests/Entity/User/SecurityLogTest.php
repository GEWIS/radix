<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\User\Enums\SecurityEventType;
use App\Entity\User\SecurityLog;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecurityLog::class)]
final class SecurityLogTest extends TestCase
{
    /**
     * A member's export shows the category and the flat detail so the event is recognisable, and leaves out the request
     * id.
     */
    public function testTheExportCarriesWhatTheMemberNeedsToRecogniseTheEvent(): void
    {
        $log = new SecurityLog();
        $log->occurredAt = new DateTimeImmutable('2026-03-01 12:00:00');
        $log->event = SecurityEventType::SignInSucceeded;
        $log->userIdentifier = 'member-8000';
        $log->firewallName = 'main';
        $log->actorIdentifier = 'member-8025';
        $log->ipAddress = '192.0.2.1';
        $log->browser = 'Firefox 128';
        $log->operatingSystem = 'Linux';
        $log->requestId = 'request-1';
        $log->detail = ['session' => 'series-1'];

        $export = $log->toGdprArray();

        self::assertSame(
            '2026-03-01T12:00:00+01:00',
            $export['occurredAt'],
        );
        self::assertSame(
            SecurityEventType::SignInSucceeded->value,
            $export['event'],
        );
        self::assertSame(
            SecurityEventType::SignInSucceeded->category()->value,
            $export['category'],
        );
        self::assertSame(
            'main',
            $export['firewall'],
        );
        self::assertSame(
            'member-8025',
            $export['actor'],
        );
        self::assertSame(
            '192.0.2.1',
            $export['ipAddress'],
        );
        self::assertSame(
            'Firefox 128',
            $export['browser'],
        );
        self::assertSame(
            'Linux',
            $export['operatingSystem'],
        );
        self::assertSame(
            ['session' => 'series-1'],
            $export['detail'],
        );
        self::assertArrayNotHasKey(
            'requestId',
            $export,
        );
        self::assertArrayNotHasKey(
            'userIdentifier',
            $export,
        );
    }
}
