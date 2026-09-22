<?php

declare(strict_types=1);

namespace App\Service\Application;

use Symfony\Component\HttpFoundation\Request;

use function in_array;
use function is_string;

/**
 * Which language a visitor wants, for the few addresses with no room for it: the bare `/`, the short `/join`, and the
 * ones kept serving links sent before the language moved into the path.
 */
final readonly class LocalePreference
{
    private const string SESSION_KEY = '_locale';

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
        $remembered = $request->hasPreviousSession()
            ? $request->getSession()->get(self::SESSION_KEY)
            : null;

        if (
            is_string($remembered)
            && in_array(
                $remembered,
                $this->supportedLocales,
                true,
            )
        ) {
            return $remembered;
        }

        return $request->getPreferredLanguage($this->supportedLocales) ?? $this->defaultLocale;
    }

    public function remember(Request $request): void
    {
        $locale = $request->attributes->get('_locale');

        if (
            !is_string($locale)
            || !in_array(
                $locale,
                $this->supportedLocales,
                true,
            )
        ) {
            return;
        }

        $session = $request->getSession();

        if ($locale === $session->get(self::SESSION_KEY)) {
            return;
        }

        $session->set(
            self::SESSION_KEY,
            $locale,
        );
    }
}
