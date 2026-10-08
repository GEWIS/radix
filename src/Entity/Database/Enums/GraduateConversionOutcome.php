<?php

declare(strict_types=1);

namespace App\Entity\Database\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum GraduateConversionOutcome: string implements TranslatableInterface
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case RemovalRequested = 'removal_requested';
    case Superseded = 'superseded';

    public function getName(): TranslatableMessage
    {
        return match ($this) {
            self::Pending => new TranslatableMessage('Not answered'),
            self::Accepted => new TranslatableMessage('Accepted'),
            self::Declined => new TranslatableMessage('Declined'),
            self::RemovalRequested => new TranslatableMessage('Declined, removal requested'),
            self::Superseded => new TranslatableMessage('Settled by the secretary'),
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
