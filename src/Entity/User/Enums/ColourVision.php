<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

use Override;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which pair of colours a revision review marks additions and removals with. The default is green and red, which
 * red-green colour blindness cannot tell apart. The other cases are named after the deficiency their pair is for.
 */
enum ColourVision: string implements TranslatableInterface
{
    case Default = 'default';

    /** Blue and orange. */
    case RedGreen = 'red-green';

    /** Blue and red. */
    case BlueYellow = 'blue-yellow';

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return match ($this) {
            self::Default => $translator->trans(
                'Default',
                locale: $locale,
            ),
            self::RedGreen => $translator->trans(
                'Protanopia and deuteranopia',
                locale: $locale,
            ),
            self::BlueYellow => $translator->trans(
                'Tritanopia',
                locale: $locale,
            ),
        };
    }
}
