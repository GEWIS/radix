<?php

declare(strict_types=1);

namespace App\Entity\Database\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MembershipProblems: string implements TranslatableInterface
{
    case EndsBeforeItStarts = 'ends_before_it_starts';
    case Overlapping = 'overlapping';
    case StartsOnTheSameDay = 'starts_on_the_same_day';

    public function getName(): TranslatableMessage
    {
        return match ($this) {
            self::EndsBeforeItStarts => new TranslatableMessage('A membership ends before it starts'),
            self::Overlapping => new TranslatableMessage('Two memberships cover the same days'),
            self::StartsOnTheSameDay => new TranslatableMessage('Two memberships start on the same day'),
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->getName()->trans(
            $translator,
            $locale,
        );
    }
}
