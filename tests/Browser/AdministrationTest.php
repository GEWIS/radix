<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

/**
 * The administrative pages, reached the way a member reaches them.
 *
 * Signing in through the form is the only way a browser test gets past the firewall, and it is worth asserting on its
 * own: it grants sudo mode as a side effect, so a failure here is what every other administrative test would report
 * as a redirect it did not expect.
 *
 * The news editor is here because it mounts CKEditor, which is the heaviest thing on any page in this application and
 * the one most likely to be left in a state it cannot recover from. For now this only asserts that it starts.
 */
final class AdministrationTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testSigningInReachesTheAdministration(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/pages',
        );

        // Reaching this at all means the firewall let the account through and sudo mode was granted by the sign-in;
        // without either, the request would have been sent somewhere else entirely.
        self::assertStringContainsString(
            '/en/admin/pages',
            $client->getCurrentURL(),
        );
    }

    public function testTheNewsEditorStartsItsEditor(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        // The news form rather than the page one, which is a stepper whose editor is on the step after the first.
        $client->request(
            'GET',
            '/en/admin/news/create',
        );

        // The textarea is server-rendered; `.ck-editor` is not. Finding one means the module loaded, the controller
        // connected and CKEditor replaced the field it was given.
        $client->waitFor('.ck-editor');

        self::assertSelectorExists('.ck-editor');
    }

    /**
     * A back navigation is rendered from a snapshot Turbo makes before leaving, and that snapshot is made before
     * Stimulus is disconnected, so an editor that injects its own markup is part of it. That did not turn out to
     * leave a second editor behind, and nothing here works around it; this is the guard that says so, because the
     * editor is the heaviest thing on any page here and the one whose duplication would be least obvious.
     */
    public function testTheEditorIsNotDuplicatedWhenThePageIsRestoredFromTheCache(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/news/create',
        );
        $client->waitFor('.ck-editor');

        // The form is written in two languages side by side, so what matters is that the number does not grow, not
        // what the number is.
        $opened = $client->getCrawler()->filter('.ck-editor')->count();

        // Driven through Turbo rather than by asking for the address, so it is a visit with a snapshot behind it
        // rather than a fresh document.
        $client->executeScript('Turbo.visit("/en/admin/news");');
        $client->waitForInvisibility('.ck-editor');

        $client->back();
        $client->waitFor('.ck-editor');

        self::assertCount(
            $opened,
            $client->getCrawler()->filter('.ck-editor'),
        );
    }
}
