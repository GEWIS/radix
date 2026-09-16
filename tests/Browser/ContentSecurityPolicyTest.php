<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

use function intval;
use function str_contains;
use function strval;

/**
 * Whether a page rendered by Turbo still satisfies the policy of the document it was rendered into.
 *
 * Turbo re-inserts the scripts of the page it renders. The nonce on those elements comes from the response it
 * fetched, and every response carries a nonce of its own, so the document already open lists a different one and
 * refuses them. `<meta name="csp-nonce">` in the head is what Turbo reads instead.
 *
 * A refusal is reported to the console and nowhere else, so nothing fails: the page renders with its scripts missing.
 * The browser also fires an event for it, which is what this counts.
 */
final class ContentSecurityPolicyTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testNavigatingWithTurboViolatesNoPolicy(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);
        $client->request(
            'GET',
            '/en/',
        );
        $client->waitFor('[data-controller~="notifications"]');

        $probe = [];
        $client->executeScript(<<<'JS'
            window.__violations = [];
            document.addEventListener('securitypolicyviolation', (event) => {
                window.__violations.push(
                    event.violatedDirective + ' ' + event.blockedURI + ' @' + event.sourceFile + ':' + event.lineNumber,
                );
            });
        JS);

        // The activity editor is the heaviest page here: a stepper, an editor and several controllers of its own.
        foreach (
            [
                '/en/admin/activities',
                '/en/admin/activities/45/edit',
                '/en/admin/activities',
            ] as $page
        ) {
            $client->executeScript('Turbo.visit("' . $page . '");');

            // The layout is on every page, so an element in it would not be waited for at all. The path changes.
            $client->wait()->until(
                static fn (): bool => $page === strval($client->executeScript('return window.location.pathname;')),
            );
        }

        // Moving between the steps of a stepper is the case that showed this: the submission redirects and Turbo
        // renders the answer, which is when the scripts of the new page are put into the document already open.
        $client->executeScript('Turbo.visit("/en/admin/activities/45/edit");');
        $client->wait()->until(
            static fn (): bool => str_contains(
                strval($client->executeScript('return window.location.pathname;')),
                '/edit',
            ),
        );

        $client->waitFor('button[name$="[next]"], [data-flow-step]');
        $client->executeScript(<<<'JS'
            const next = document.querySelector('button[name$="[next]"]');

            if (null !== next) {
                next.click();
            }
        JS);

        $client->wait()->until(
            static fn (): bool => 0 < intval($client->executeScript('return window.__violations.length;'))
                || null === $client->executeScript('return document.querySelector(".turbo-progress-bar");'),
        );

        self::assertSame(
            [],
            (array) $client->executeScript('return window.__violations;'),
        );
    }
}
