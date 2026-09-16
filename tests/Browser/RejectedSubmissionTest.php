<?php

declare(strict_types=1);

namespace App\Tests\Browser;

/**
 * A rejected submission now returns 422 rather than 200, and this is the test that it still reaches the member.
 *
 * The status is what Turbo needs to render a rejection in place of the page, but a status is also easy to change
 * without noticing what else it decides. Nothing else in the suite looks at a rejected form the way a browser does.
 */
final class RejectedSubmissionTest extends BrowserTestCase
{
    /**
     * The password reset request form is public, so it needs no account, and it rejects an empty submission on more
     * than one field, so the errors are not a single special case.
     */
    public function testAFormThatIsRejectedStillShowsWhatIsWrongWithIt(): void
    {
        $client = static::createPantherClient();
        $client->request(
            'GET',
            '/en/user/forgot-password',
        );

        // The browser would otherwise refuse to submit this on the required fields alone, and never reach the server
        // whose response is what is being tested. What the fields contain does not matter: the proof of work guarding
        // the form is unsolved either way, so the submission is rejected and comes back with its errors on it.
        $client->executeScript('document.querySelector("form").setAttribute("novalidate", "novalidate");');

        $client->submitForm('Send password reset email');

        $client->waitFor('.invalid-feedback');

        self::assertSelectorExists('.invalid-feedback');
    }
}
