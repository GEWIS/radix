<?php

declare(strict_types=1);

namespace App\Tests\Twig\Extensions;

use App\Twig\Extensions\RelativeTimeExtension;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function explode;
use function str_replace;

/**
 * The coarse duration used in deadline labels: the largest unit that has elapsed, singular or plural, never a
 * negative age.
 */
final class RelativeTimeExtensionTest extends TestCase
{
    #[DataProvider('spans')]
    public function testTheLargestUnitThatElapsedIsTheOneNamed(
        string $from,
        string $to,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->extension()->humanTimeDiff(
                new DateTimeImmutable($from),
                new DateTimeImmutable($to),
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function spans(): iterable
    {
        yield 'less than a minute' => [
            '2026-09-03 12:40:00',
            '2026-09-03 12:40:30',
            'less than a minute',
        ];

        yield 'one minute' => [
            '2026-09-03 12:40:00',
            '2026-09-03 12:41:00',
            '1 minute',
        ];

        yield 'several minutes' => [
            '2026-09-03 12:40:00',
            '2026-09-03 12:45:00',
            '5 minutes',
        ];

        yield 'one hour' => [
            '2026-09-03 12:00:00',
            '2026-09-03 13:00:00',
            '1 hour',
        ];

        yield 'several hours' => [
            '2026-09-03 12:00:00',
            '2026-09-03 17:00:00',
            '5 hours',
        ];

        yield 'one day' => [
            '2026-09-03 12:00:00',
            '2026-09-04 12:00:00',
            '1 day',
        ];

        yield 'several days' => [
            '2026-09-03 12:00:00',
            '2026-09-06 12:00:00',
            '3 days',
        ];

        yield 'whole weeks' => [
            '2026-09-03 12:00:00',
            '2026-09-17 12:00:00',
            '2 weeks',
        ];

        // A week and a day is still counted in weeks: the unit is the largest one that has elapsed.
        yield 'a week and a half' => [
            '2026-09-03 12:00:00',
            '2026-09-13 12:00:00',
            '1 week',
        ];
    }

    public function testAPastMomentIsDescribedAsAnAge(): void
    {
        $moment = new DateTimeImmutable('-3 hours');

        self::assertStringEndsWith(
            'ago',
            $this->extension()->relativeTime($moment),
        );
        self::assertStringContainsString(
            '3 hours',
            $this->extension()->relativeTime($moment),
        );
    }

    /**
     * A moment that has not happened yields no negative age.
     */
    public function testAFutureMomentIsDescribedAsSuch(): void
    {
        $moment = new DateTimeImmutable('+3 days');

        self::assertStringStartsWith(
            'in ',
            $this->extension()->relativeTime($moment),
        );
        self::assertStringContainsString(
            '2 days',
            $this->extension()->relativeTime($moment),
        );
    }

    private function extension(): RelativeTimeExtension
    {
        return new RelativeTimeExtension(
            new class implements
                TranslatorInterface,
                LocaleAwareInterface
            {
                private string $locale = 'en';

                /**
                 * @param array<array-key, mixed> $parameters
                 */
                #[Override]
                public function trans(
                    string $id,
                    array $parameters = [],
                    ?string $domain = null,
                    ?string $locale = null,
                ): string {
                    $message = $id;
                    $count = $parameters['%count%'] ?? null;
                    if (null !== $count) {
                        $forms = explode(
                            '|',
                            $id,
                        );
                        $message = 1 === (int) $count
                            ? $forms[0]
                            : $forms[count($forms) - 1];
                    }

                    foreach ($parameters as $key => $value) {
                        $message = str_replace(
                            (string) $key,
                            (string) $value,
                            $message,
                        );
                    }

                    return $message;
                }

                #[Override]
                public function getLocale(): string
                {
                    return $this->locale;
                }

                #[Override]
                public function setLocale(string $locale): void
                {
                    $this->locale = $locale;
                }
            },
        );
    }
}
