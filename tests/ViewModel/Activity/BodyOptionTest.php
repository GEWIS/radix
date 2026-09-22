<?php

declare(strict_types=1);

namespace App\Tests\ViewModel\Activity;

use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Organ;
use App\ViewModel\Activity\BodyOption;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_map;

/**
 * An abbreviation is reused, so a former body under the same letters as a standing one is labelled with the years it
 * existed. A body with a unique abbreviation, abrogated or not, is labelled with the abbreviation alone.
 */
final class BodyOptionTest extends TestCase
{
    public function testAUniqueAbbreviationIsTheWholeLabel(): void
    {
        $options = BodyOption::fromOrgans([
            $this->body(
                1,
                'KEUR',
                2010,
                null,
            ),
            $this->body(
                2,
                'OUD',
                2005,
                2012,
            ),
        ]);

        self::assertSame(
            [
                'KEUR',
                'OUD',
            ],
            $this->labels($options),
        );
        self::assertFalse($options[0]->abrogated);
        self::assertTrue($options[1]->abrogated);
    }

    public function testAFormerBodyUnderAReusedAbbreviationIsLabelledWithItsYears(): void
    {
        $options = BodyOption::fromOrgans([
            $this->body(
                1,
                'GETÉST',
                2014,
                2019,
            ),
            $this->body(
                2,
                'GETÉST',
                2024,
                null,
            ),
        ]);

        self::assertSame(
            [
                'GETÉST (2014 - 2019)',
                'GETÉST',
            ],
            $this->labels($options),
        );
        self::assertSame(
            [
                1,
                2,
            ],
            array_map(
                static fn (BodyOption $option): int => $option->id,
                $options,
            ),
        );
    }

    /**
     * An abrogation that has not taken effect yet leaves the body standing until that day.
     */
    public function testABodyAbrogatedInTheFutureIsStillActive(): void
    {
        $options = BodyOption::fromOrgans([
            $this->body(
                1,
                'GETÉST',
                2014,
                (int) (new DateTimeImmutable('+2 years'))->format('Y'),
            ),
            $this->body(
                2,
                'GETÉST',
                2024,
                null,
            ),
        ]);

        self::assertSame(
            [
                'GETÉST',
                'GETÉST',
            ],
            $this->labels($options),
        );
        self::assertFalse($options[0]->abrogated);
    }

    /**
     * @param list<BodyOption> $options
     *
     * @return list<string>
     */
    private function labels(array $options): array
    {
        return array_map(
            static fn (BodyOption $option): string => $option->label,
            $options,
        );
    }

    private function body(
        int $id,
        string $abbreviation,
        int $foundedIn,
        ?int $abrogatedIn,
    ): Organ {
        $organ = new Organ();
        $organ->abbr = $abbreviation;
        $organ->name = 'A committee';
        $organ->type = OrganTypes::Committee;
        $organ->foundationDate = new DateTimeImmutable($foundedIn . '-05-19');

        if (null !== $abrogatedIn) {
            $organ->abrogationDate = new DateTimeImmutable($abrogatedIn . '-12-31');
        }

        // The identifier is assigned by Doctrine, so a fresh entity has none.
        new ReflectionProperty(
            Organ::class,
            'id',
        )->setValue(
            $organ,
            $id,
        );

        return $organ;
    }
}
