<?php

declare(strict_types=1);

namespace App\Tests\Browser;

/**
 * The harness itself, and the two halves of the front end it depends on.
 *
 * Nothing here is interesting behaviour. It is the test that fails first and most clearly when the harness is wrong:
 * when the server is not serving, when the content security policy of the test environment refuses the scripts, when
 * the importmap does not resolve, or when Stimulus never starts. A failure anywhere else is much harder to read if
 * this is not known to pass.
 */
final class FrontEndBootsTest extends BrowserTestCase
{
    /**
     * The theme is applied by a script the layout inlines in the head, so that it is on the document before the first
     * paint rather than after it. It runs whether or not the module graph ever loads.
     */
    public function testTheThemeIsOnTheDocument(): void
    {
        $client = static::createPantherClient();
        $client->request(
            'GET',
            '/en/',
        );

        // Read through the browser rather than the crawler, whose node list starts below the element being asked about.
        self::assertContains(
            $client->executeScript('return document.documentElement.getAttribute("data-bs-theme");'),
            [
                'gewis-day',
                'gewis-night',
            ],
        );
    }

    /**
     * Nothing server side marks a theme button as the one in use: the `theme-switcher` controller does it when it
     * connects. Finding it pressed means the importmap resolved, Stimulus started and a controller reached its
     * element, which is the whole front end in one assertion.
     */
    public function testTheThemeSwitcherMarksTheThemeInUse(): void
    {
        $client = static::createPantherClient();
        $client->request(
            'GET',
            '/en/',
        );

        $client->waitFor('[data-bs-theme-value][aria-pressed="true"]');

        self::assertSelectorExists('[data-bs-theme-value][aria-pressed="true"]');
    }
}
