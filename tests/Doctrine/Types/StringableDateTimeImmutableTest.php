<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Types;

use App\Doctrine\Types\StringableDateTimeImmutable;
use App\Doctrine\Types\StringableDateTimeImmutableType;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

/**
 * The stringable variant lets a date be used where a string is expected without losing its timezone, and the column
 * type reads it back as that variant.
 */
final class StringableDateTimeImmutableTest extends TestCase
{
    public function testItRendersAsATimestamp(): void
    {
        $date = new StringableDateTimeImmutable(
            '2026-08-31 12:00:00',
            new DateTimeZone('Europe/Amsterdam'),
        );

        self::assertSame(
            $date->format('U'),
            (string) $date,
        );
    }

    public function testItConvertsBackToAPlainDateInTheSameTimezone(): void
    {
        $date = new StringableDateTimeImmutable(
            '2026-08-31 12:00:00',
            new DateTimeZone('Europe/Amsterdam'),
        );

        $plain = $date->toDateTime();

        self::assertSame(
            $date->format('U'),
            $plain->format('U'),
        );
        self::assertSame(
            'Europe/Amsterdam',
            $plain->getTimezone()->getName(),
        );
    }

    public function testItIsBuiltFromAPlainDateInTheSameTimezone(): void
    {
        $plain = new DateTimeImmutable(
            '2026-08-31 12:00:00',
            new DateTimeZone('Europe/Amsterdam'),
        );

        $date = StringableDateTimeImmutable::fromDateTime($plain);

        self::assertSame(
            $plain->format('U'),
            $date->format('U'),
        );
        self::assertSame(
            'Europe/Amsterdam',
            $date->getTimezone()->getName(),
        );
    }

    public function testTheColumnTypeReadsAValueBackAsTheStringableVariant(): void
    {
        $type = new StringableDateTimeImmutableType();

        $value = $type->convertToPHPValue(
            '2026-08-31 12:00:00',
            new PostgreSQLPlatform(),
        );

        self::assertInstanceOf(
            StringableDateTimeImmutable::class,
            $value,
        );
        self::assertSame(
            '2026-08-31 12:00:00',
            $value->format('Y-m-d H:i:s'),
        );
    }

    public function testTheColumnTypeReadsNullAsNull(): void
    {
        self::assertNull(
            new StringableDateTimeImmutableType()->convertToPHPValue(
                null,
                new PostgreSQLPlatform(),
            ),
        );
    }
}
