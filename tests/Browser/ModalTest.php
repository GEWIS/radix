<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

/**
 * What an open dialog leaves in the document after a navigation away from its page.
 *
 * Bootstrap adds the backdrop and a class to `body`. Both are inside the element Turbo replaces, so both are removed
 * with it. Without that, the next page would render normally and refuse every click.
 */
final class ModalTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testAnOpenDialogLeavesNothingBehindOnTheNextPage(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/pages',
        );

        $client->waitFor('[data-bs-toggle="modal"]');
        $client->executeScript(<<<'JS'
            document.querySelector('[data-bs-toggle="modal"]').click();
        JS);
        $client->waitForVisibility('.modal.show');

        $client->executeScript('Turbo.visit("/en/admin/news");');
        $client->waitForInvisibility('.modal.show');

        self::assertSame(
            'none left',
            $client->executeScript(<<<'JS'
                const backdrops = document.querySelectorAll('.modal-backdrop').length;
                const locked = document.body.classList.contains('modal-open');

                return (0 === backdrops && !locked)
                    ? 'none left'
                    : `backdrops=${backdrops} locked=${locked}`;
            JS),
        );
    }
}
