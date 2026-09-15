<?php

declare(strict_types=1);

namespace App\Doctrine\Types;

// Source - https://stackoverflow.com/a/15085566
// Posted by Ocramius, modified by community. See post 'Timeline' for change history
// Retrieved 2026-06-15, License - CC BY-SA 4.0, relicensed under GPL-3.0 on 2026-06-15

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Override;

/**
 * Dropping requiresSQLCommentHint(), which DBAL 4 removed, stops the schema tool emitting the
 * `COMMENT ON COLUMN ... IS '(DC2Type:...)'` marker. The column type itself is unchanged.
 */
class StringableDateTimeType extends DateTimeImmutableType
{
    /**
     * {@inheritDoc}
     *
     * Narrowing the parent's `?DateTimeImmutable` to `?StringableDateTime` is safe, since StringableDateTime extends
     * DateTimeImmutable, and is what lets entities type their properties as the stringable variant.
     */
    #[Override]
    public function convertToPHPValue(
        mixed $value,
        AbstractPlatform $platform,
    ): ?StringableDateTime {
        $dateTime = parent::convertToPHPValue(
            $value,
            $platform,
        );

        if (null === $dateTime) {
            return null;
        }

        return new StringableDateTime('@' . $dateTime->format('U'))->setTimezone($dateTime->getTimezone());
    }
}
