<?php

declare(strict_types=1);

namespace App\Doctrine\Types;

// Source - https://stackoverflow.com/a/15085566
// Posted by Ocramius, modified by community. See post 'Timeline' for change history
// Retrieved 2026-06-15, License - CC BY-SA 4.0, relicensed under GPL-3.0 on 2026-06-15

use DateTimeImmutable;
use NoDiscard;

class StringableDateTime extends DateTimeImmutable
{
    public function __toString(): string
    {
        return $this->format('U');
    }

    #[NoDiscard]
    public function toDateTime(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->format(DateTimeImmutable::ATOM))->setTimezone($this->getTimezone());
    }

    #[NoDiscard]
    public static function fromDateTime(DateTimeImmutable $dateTime): self
    {
        return new self($dateTime->format(DateTimeImmutable::ATOM))->setTimezone($dateTime->getTimezone());
    }
}
