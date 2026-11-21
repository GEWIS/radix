<?php

declare(strict_types=1);

namespace App\Tests\Entity\Database\Enums;

use App\Entity\Database\Enums\PostalRegions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function array_unique;
use function count;

#[CoversClass(PostalRegions::class)]
final class PostalRegionsTest extends TestCase
{
    /**
     * The case value is the envelope label as PostNL writes it, so the catalogue is keyed on it and the enum does not
     * restate it in a list that can drift.
     */
    public function testTheNameIsTheRegionAsWrittenOnAnEnvelope(): void
    {
        self::assertSame(
            'NETHERLANDS',
            PostalRegions::Netherlands->getName()->getMessage(),
        );

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        self::assertSame(
            'NETHERLANDS',
            PostalRegions::Netherlands->trans($translator),
        );
    }

    /**
     * The form stores the value and accepts either the string or the case, so both have to be in the list of allowed
     * values and every case has to appear exactly once.
     */
    public function testTheValuesListContainsBothTheStringsAndTheCases(): void
    {
        $values = PostalRegions::values();

        self::assertCount(
            2 * count(PostalRegions::cases()),
            $values,
        );
        self::assertContains(
            'NETHERLANDS',
            $values,
        );
        self::assertContains(
            PostalRegions::Netherlands,
            $values,
        );
        self::assertCount(
            count(PostalRegions::cases()),
            array_unique(array_map(
                static fn (PostalRegions|string $value): string => $value instanceof PostalRegions
                    ? $value->value
                    : $value,
                $values,
            )),
        );
    }

    /**
     * A case value is unique to its case; an accidental duplicate would make the form silently choose the wrong
     * region.
     */
    public function testEveryCaseValueIsUnique(): void
    {
        $values = array_map(
            static fn (PostalRegions $region): string => $region->value,
            PostalRegions::cases(),
        );

        self::assertCount(
            count($values),
            array_unique($values),
        );
    }
}
