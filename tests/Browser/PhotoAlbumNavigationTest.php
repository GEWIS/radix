<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Symfony\Component\Panther\Client;

use function strval;

/**
 * Navigating from a virtual album (member photos, weekly photos) to the original album, and back.
 *
 * The PhotoSwipe viewer adds its own hash-based history entries. When the "Go to the original album" button is
 * clicked, the viewer must close before the navigation happens, or the history stack gets polluted and the back
 * button returns the wrong page.
 */
final class PhotoAlbumNavigationTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testBackFromOriginalAlbumReturnsToMemberPhotos(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        // Member 8030 is tagged in several seeded photos.
        $client->request(
            'GET',
            '/en/photos/member/8030',
        );

        // Wait for the grid to load.
        $client->waitFor('[data-gallery-target="grid"] a');

        // Open the first photo in the viewer.
        $client->executeScript('document.querySelector(\'[data-view-label] a\').click();');

        // Wait for the PhotoSwipe viewer to open.
        $client->waitFor('.pswp');

        // Wait for the album button to become visible (the change event must fire first).
        $client->wait()->until(
            static fn (): bool => (bool) $client->executeScript(
                'const btn = document.querySelector(".pswp__button--album-button");'
                . ' return btn && !btn.hidden;',
            ),
        );

        // Click the "Go to the original album" button.
        $client->executeScript('document.querySelector(".pswp__button--album-button").click();');

        // Wait for the navigation to complete. The URL should now be the album page.
        $client->waitFor('body');
        $albumPath = $this->path($client);
        self::assertStringContainsString(
            '/photos/album/',
            $albumPath,
        );

        // Verify the viewer is closed (no PhotoSwipe overlay).
        self::assertSelectorNotExists('.pswp__container');

        // Go back in history.
        $client->navigate()->back();

        // Wait for the navigation to complete.
        $client->waitFor('body');
        $memberPath = $this->path($client);

        // Should be back on the member's photos page, not the album page.
        self::assertSame(
            '/en/photos/member/8030',
            $memberPath,
        );
        self::assertNotSame(
            $albumPath,
            $memberPath,
        );
    }

    private function path(Client $client): string
    {
        return strval($client->executeScript('return window.location.pathname;'));
    }
}
