<?php

declare(strict_types=1);

namespace App\Service\Application;

use Symfony\Component\HttpFoundation\Request;

/**
 * Which language a visitor wants, for the few addresses with no room to say: the bare `/`, the short `/join`, and the
 * ones kept answering for links sent before the language moved into the path.
 */
final readonly class LocalePreference
{
    /**
     * @param string[] $supportedLocales
     */
    public function __construct(
        private array $supportedLocales,
        private string $defaultLocale,
    ) {
    }

    public function resolve(Request $request): string
    {
        return $request->getPreferredLanguage($this->supportedLocales) ?? $this->defaultLocale;
    }
}
