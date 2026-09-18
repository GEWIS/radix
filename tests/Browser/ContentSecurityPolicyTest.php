<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Symfony\Component\Panther\Client;

use function intval;
use function strval;

/**
 * Whether a page rendered by Turbo still satisfies the policy of the document it was rendered into.
 *
 * A document is subject to the policy of the response it was loaded from, and that policy lists the nonce of that one
 * response. Turbo renders every later response into that same document, re-creating the script elements of the
 * response it fetched, and the `nonce` attribute it copies onto them is the one that response was written with
 * (hotwired/turbo#294). None of those elements is permitted by its nonce, so what permits them is `strict-dynamic`:
 * a script element created by an already-trusted script is allowed under it whatever its nonce. That is what these
 * two check, because nothing else in the policy applies to them once `strict-dynamic` makes a host source inert.
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

        $this->recordViolations($client);

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

        self::assertSame(
            [],
            $client->executeScript('return window.__violations;'),
        );
    }

    /**
     * The same, for a response to a submission rather than to a visit. It is the path a refusal was reported on, and
     * it reaches the renderer differently: the step buttons of a stepper submit the form, and what is rendered is the
     * page the redirect after it leads to.
     */
    public function testSubmittingAStepViolatesNoPolicy(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);
        $client->request(
            'GET',
            '/en/admin/activities/45/edit',
        );
        $client->waitFor('button.form-stepper__btn');

        $this->recordViolations($client);
        $client->executeScript(<<<'JS'
            window.__renders = 0;
            document.addEventListener('turbo:render', () => { window.__renders += 1; });
            document.querySelector('button.form-stepper__btn').click();
        JS);

        $client->wait()->until(
            static fn (): bool => 0 < intval($client->executeScript('return window.__renders;')),
        );

        self::assertSame(
            [],
            $client->executeScript('return window.__violations;'),
        );
    }

    /**
     * Records every policy violation the page reports from here on, so the assertion can read them back. Stated once
     * because a difference between the two tests would be a difference in what they report, not in what they cover.
     */
    private function recordViolations(Client $client): void
    {
        $client->executeScript(<<<'JS'
            window.__violations = [];
            document.addEventListener('securitypolicyviolation', (event) => {
                window.__violations.push(
                    event.violatedDirective + ' ' + event.blockedURI + ' @' + event.sourceFile + ':' + event.lineNumber,
                );
            });
        JS);
    }
}
