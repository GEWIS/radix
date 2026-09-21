<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Database;

use App\Entity\Database\Enums\MeetingTypes;
use App\ViewModel\Database\MeetingNumberSuggestion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MeetingNumberSuggestionTest extends TestCase
{
    public function testTheNextNumberFollowsTheLatestOne(): void
    {
        $suggestion = new MeetingNumberSuggestion(
            MeetingTypes::BV,
            1749,
            new DateTimeImmutable('2026-09-12'),
            [1748],
        );

        self::assertSame(
            1750,
            $suggestion->next(),
        );
    }

    public function testOnlyAVirtualMeetingIsExpectedToBeBackdated(): void
    {
        foreach (MeetingTypes::cases() as $type) {
            $suggestion = new MeetingNumberSuggestion(
                $type,
                1,
                new DateTimeImmutable('2026-09-12'),
                [],
            );

            self::assertSame(
                MeetingTypes::VIRT !== $type,
                $suggestion->datesFollowNumbers(),
            );
        }
    }

    public function testATypeWithoutMeetingsStartsAtOne(): void
    {
        $suggestion = new MeetingNumberSuggestion(
            MeetingTypes::VIRT,
            null,
            null,
            [],
        );

        self::assertSame(
            1,
            $suggestion->next(),
        );
    }
}
