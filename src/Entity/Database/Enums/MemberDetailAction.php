<?php

declare(strict_types=1);

namespace App\Entity\Database\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum MemberDetailAction: string implements TranslatableInterface
{
    case Added = 'added';
    case Changed = 'changed';
    case Removed = 'removed';

    public function getName(): TranslatableMessage
    {
        return match ($this) {
            self::Added => new TranslatableMessage('Added'),
            self::Changed => new TranslatableMessage('Changed'),
            self::Removed => new TranslatableMessage('Removed'),
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
