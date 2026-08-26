<?php

declare(strict_types=1);

namespace App\Entity\Application\Traits;

use App\Entity\Application\Enums\AppLanguages;
use DateTime;
use IntlDateFormatter;

use function date_default_timezone_get;

trait FormattableDateTrait
{
    /**
     * Format a `DateTime` in a specified locale.
     *
     * With {@see IntlDateFormatter::LONG} the date will be formatted using the day of the month, full month, and
     * 4-digit year. For example, for
     */
    private function formatDate(
        DateTime $date,
        AppLanguages $language = AppLanguages::Dutch,
    ): string {
        $formatter = new IntlDateFormatter(
            $language->getLocale(),
            IntlDateFormatter::LONG,
            IntlDateFormatter::NONE,
            date_default_timezone_get(),
        );

        $formatted = $formatter->format($date);

        // `IntlDateFormatter::format()` answers `false` when it cannot format what it was given. A `DateTime` and one
        // of the two locales the application has are never that, but a decision has to read as something either way,
        // so the date falls back to the way it is written down rather than to a type error.
        return false === $formatted
            ? $date->format('Y-m-d')
            : $formatted;
    }
}
