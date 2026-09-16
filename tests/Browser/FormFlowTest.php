<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

/**
 * A stepper, which is the part of this application that changed most in becoming post/redirect/get.
 *
 * Every step but the last used to be rendered into the response of the POST that submitted the one before it. They
 * return a redirect now, so what a step is rendered from is a GET of its own and the state in it has to survive a
 * round trip through the session rather than staying in one request. That is exactly the kind of change
 * that works when read and fails when run.
 *
 * Nothing here finishes a flow, so nothing is written: these tests run against a server whose writes are not rolled
 * back.
 */
final class FormFlowTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testAStepperKeepsItsStateAcrossTheRedirect(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/pages/create',
        );

        // The first step addresses the page. Every field on it is optional on its own, but the address as a whole has
        // to be one no page and no route is already served at, so it is filled with something nothing else claims. The
        // name is matched on its ending because the prefix belongs to the flow rather than to the step.
        $client->waitFor('input[name$="[categoryEN]"]');
        $client->executeScript(<<<'JS'
            document
                .querySelectorAll('input[name$="[categoryNL]"], input[name$="[categoryEN]"]')
                .forEach((field) => {
                    field.value = 'turbo-flow-test';
                });
        JS);

        $client->submitForm('Next');

        // The content step is the only one that mounts an editor. Reaching it means the step was accepted, the flow
        // moved, and the run was found again through the redirect rather than starting over.
        $client->waitFor('.ck-editor');

        self::assertSelectorExists('.ck-editor');
    }

    /**
     * Becoming a member, which is the one stepper a visitor reaches and the only one whose failure costs the
     * association money. It keeps one flow per session rather than one per arrival, so unlike the rest it has no run
     * key to be kept through the redirect, and the address it goes back to has to be the one it came from.
     *
     * The flow is not finished here, so no prospective member is created and nothing is written.
     */
    public function testBecomingAMemberKeepsItsStateAcrossTheRedirect(): void
    {
        $client = static::createPantherClient();
        $client->request(
            'GET',
            '/en/join',
        );

        $client->waitFor('input[name$="[initials]"]');
        $client->executeScript(<<<'JS'
            const fill = (name, value) => {
                const field = document.querySelector(`[name$="[${name}]"]`);

                if (null !== field) {
                    field.value = value;
                }
            };

            fill('initials', 'T.');
            fill('firstName', 'Turbo');
            fill('lastName', 'Test');
            // A real domain, because the step checks that the address could receive mail by looking up its MX
            // records. A reserved one such as example.invalid is rejected, which is the point of the check.
            fill('email', 'turbo.test@gewis.nl');
            fill('birth', '2000-01-01');
        JS);

        $client->submitForm('Next');

        // The student number belongs to the step after the personal one, so finding it means the flow moved and found
        // itself again on the other side of the redirect.
        $client->waitFor('input[name$="[studentNumber]"]');

        self::assertSelectorExists('input[name$="[studentNumber]"]');
    }
}
