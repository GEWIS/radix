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
}
