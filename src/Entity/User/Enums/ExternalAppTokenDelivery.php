<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

use Override;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the token is handed back to an external application. Legacy applications receive it as a query parameter; modern
 * applications require the URL fragment, since those are not logged or cached the way query strings can be.
 */
enum ExternalAppTokenDelivery: string implements TranslatableInterface
{
    case Query = 'query';
    case Fragment = 'fragment';

    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::Query => new TranslatableMessage('Query parameter (?token=)'),
            self::Fragment => new TranslatableMessage('URL fragment (#token=)'),
        };
    }

    #[Override]
    public function trans(
        TranslatorInterface $translator,
        ?string $locale = null,
    ): string {
        return $this->label()->trans(
            $translator,
            $locale,
        );
    }

    public function separator(): string
    {
        return match ($this) {
            self::Query => '?token=',
            self::Fragment => '#token=',
        };
    }
}
