<?php

declare(strict_types=1);

namespace App\Entity\Application\Traits;

use App\Entity\Application\Enums\AppLanguages;
use DateTimeImmutable;
use IntlDateFormatter;

use function date_default_timezone_get;

trait FormattableDateTrait
{
    /**
     * Format a `DateTimeImmutable` in a specified locale.
     *
     * With {@see IntlDateFormatter::LONG} the date will be formatted using the day of the month, full month, and
     * 4-digit year. For example, for
     */
    private function formatDate(
        DateTimeImmutable $date,
        AppLanguages $language = AppLanguages::Dutch,
    ): string {
        $formatter = new IntlDateFormatter(
            $language->getLocale(),
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            date_default_timezone_get(),
        );

        $formatted = $formatter->format($date);

        // `IntlDateFormatter::format()` returns `false` when it cannot format its input. That does not happen for a
        // `DateTimeImmutable` in either of the application's two locales, but the fallback is the ISO date rather than
        // a type error.
        return false === $formatted
            ? $date->format('Y-m-d')
            : $formatted;
    }
}
