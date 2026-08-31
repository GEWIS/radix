<?php

declare(strict_types=1);

namespace App\Tests\Service\Application;

use App\Service\Application\RealtimeTopics;
use PHPUnit\Framework\TestCase;

use function str_contains;

final class RealtimeTopicsTest extends TestCase
{
    private const string SECRET = 'a secret that is only ever this test\'s';
    private const string SERIES = 'nu5wKr9Kx1lFhVJPBnIeUJ6NUvJyPXhMkYFXCJt3aVg';

    public function testTheTopicADeviceIsSignedOutOverDoesNotCarryItsSeries(): void
    {
        $topic = new RealtimeTopics(self::SECRET)->session(
            'main',
            self::SERIES,
        );

        self::assertFalse(str_contains(
            $topic,
            self::SERIES,
        ));
    }

    public function testTheSameSessionAlwaysReachesTheSameTopic(): void
    {
        $topics = new RealtimeTopics(self::SECRET);

        self::assertSame(
            $topics->session(
                'main',
                self::SERIES,
            ),
            $topics->session(
                'main',
                self::SERIES,
            ),
        );
    }

    public function testTwoSessionsDoNotShareATopic(): void
    {
        $topics = new RealtimeTopics(self::SECRET);

        self::assertNotSame(
            $topics->session(
                'main',
                self::SERIES,
            ),
            $topics->session(
                'main',
                'a different series entirely',
            ),
        );
    }

    public function testTheFirewallsDoNotShareATopic(): void
    {
        $topics = new RealtimeTopics(self::SECRET);

        self::assertNotSame(
            $topics->session(
                'main',
                self::SERIES,
            ),
            $topics->session(
                'company',
                self::SERIES,
            ),
        );
    }

    public function testTheTopicDoesNotCarryToAnotherSecret(): void
    {
        self::assertNotSame(
            new RealtimeTopics(self::SECRET)->session(
                'main',
                self::SERIES,
            ),
            new RealtimeTopics('somebody else\'s secret')->session(
                'main',
                self::SERIES,
            ),
        );
    }

    public function testAnAccountsOwnTopicReadsAsTheAccount(): void
    {
        self::assertSame(
            'gewis/user/company/42',
            new RealtimeTopics(self::SECRET)->user(
                'company',
                '42',
            ),
        );
    }
}
