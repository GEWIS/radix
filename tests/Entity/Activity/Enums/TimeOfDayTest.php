<?php

declare(strict_types=1);

namespace App\Tests\Entity\Activity\Enums;

use App\Entity\Activity\Enums\TimeOfDay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

#[CoversClass(TimeOfDay::class)]
final class TimeOfDayTest extends TestCase
{
    /**
     * Two options share a date only when they cover different parts of it. Anything covering a whole day collides with
     * everything, and the same part of the day collides with itself.
     */
    #[DataProvider('pairs')]
    public function testOnlyDifferentPartsOfOneDayCanShareIt(
        TimeOfDay $first,
        TimeOfDay $second,
        bool $overlaps,
    ): void {
        self::assertSame(
            $overlaps,
            $first->overlaps($second),
            $first->value . ' / ' . $second->value,
        );
        self::assertSame(
            $overlaps,
            $second->overlaps($first),
            $second->value . ' / ' . $first->value,
        );
    }

    /**
     * @return iterable<string, array{TimeOfDay, TimeOfDay, bool}>
     */
    public static function pairs(): iterable
    {
        yield 'same morning' => [
            TimeOfDay::Morning,
            TimeOfDay::Morning,
            true,
        ];

        yield 'morning and evening' => [
            TimeOfDay::Morning,
            TimeOfDay::Evening,
            false,
        ];

        yield 'lunch and afternoon' => [
            TimeOfDay::LunchBreak,
            TimeOfDay::Afternoon,
            false,
        ];

        yield 'a whole day against a morning' => [
            TimeOfDay::Day,
            TimeOfDay::Morning,
            true,
        ];

        yield 'a morning against multiple days' => [
            TimeOfDay::Morning,
            TimeOfDay::MultipleDays,
            true,
        ];

        yield 'two whole days' => [
            TimeOfDay::Day,
            TimeOfDay::MultipleDays,
            true,
        ];
    }

    public function testEveryPartOfTheDayHasItsOwnLabel(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $labels = [];
        foreach (TimeOfDay::cases() as $part) {
            $labels[$part->trans($translator)] = true;
        }

        self::assertCount(
            count(TimeOfDay::cases()),
            $labels,
        );
    }
}
