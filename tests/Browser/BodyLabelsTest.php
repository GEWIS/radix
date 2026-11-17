<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

use function str_contains;
use function strval;

/**
 * The labels a body's page carries: what the committee page and the body's page show of them, how a label ends up on
 * a draft for the board to review, and where a body that is no committee sees none of it.
 *
 * The seed gives BAC one label (Fun) on its approved page, and the committee page and the body's page are where a
 * visitor sees the result of a committee's choice. The rest of the labels exist without a page on them, which is what
 * the overview shows for them. KCC is a financial audit committee, a committee in name only: its page is seeded like
 * any other body's and it must stay label-free on every screen.
 */
final class BodyLabelsTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testTheCommitteePageShowsTheLabelsTheCommitteePicked(): void
    {
        $client = static::createPantherClient();

        $client->request(
            'GET',
            '/en/association/committees',
        );
        $client->waitFor('.organ-card');

        $cards = $client->getCrawler()->filter('.organ-card');
        $index = null;
        foreach (
            $cards as $i => $card
        ) {
            if (
                str_contains(
                    (string) $card->getText(),
                    'BAC',
                )
            ) {
                $index = $i;

                break;
            }
        }

        self::assertNotNull($index);
        $bac = $cards->eq($index);
        self::assertCount(
            1,
            $bac,
        );
        self::assertStringContainsString(
            'Fun',
            $bac->filter('.badge.badge-tag')->text(),
        );

        $client->request(
            'GET',
            '/en/association/committee/BAC',
        );
        $client->waitFor('.page-header-meta');
        self::assertStringContainsString(
            'Fun',
            $client->getCrawler()->filter('.page-header-meta .badge.badge-tag')->text(),
        );
    }

    public function testANonCommitteeShowsNoLabels(): void
    {
        $client = static::createPantherClient();

        $client->request(
            'GET',
            '/en/association/kcc/KCC',
        );
        $client->waitFor('.page-header-meta');
        self::assertCount(
            0,
            $client->getCrawler()->filter('.page-header-meta .badge.badge-tag'),
        );

        // The committee overview offers the label filter, which the overview of the other kinds of bodies does not.
        $client->request(
            'GET',
            '/en/association/committees',
        );
        $client->waitFor('.organ-card');
        self::assertCount(
            1,
            $client->getCrawler()->filter('#body-overview-labels-committee-now'),
        );

        $client->request(
            'GET',
            '/en/association/gmm-bodies/financial-audit-committees',
        );
        $client->waitFor('.organ-card');
        self::assertCount(
            0,
            $client->getCrawler()->filter('#body-overview-labels-kcc-now'),
        );
        self::assertCount(
            0,
            $client->getCrawler()->filter('.organ-card .badge.badge-tag'),
        );
    }

    public function testThePageFormOfANonCommitteeOffersNoLabels(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/bodies/8',
        );
        $client->waitFor('form[action$="/revise"], a[href$="/edit"]');

        // KCC's page is approved in the seed, like BAC's, so the first move is to start a draft.
        if (1 === $client->getCrawler()->filter('form[action$="/revise"]')->count()) {
            $client->submitForm('Revise the page');
            $client->waitFor('form[action$="/revise"], a[href$="/edit"]');
        }

        $client->request(
            'GET',
            '/en/admin/bodies/8/edit',
        );
        $client->waitFor('form');

        // The committee's form carries the picker, which testLabelsCanBePickedOnThePageAndReachTheReview pins; this
        // one must not.
        self::assertCount(
            0,
            $client->getCrawler()->filter('select[name*="labelIds"]'),
        );
        self::assertCount(
            0,
            $client->getCrawler()->filterXPath('//h3[contains(., "Labels")]'),
        );
    }

    public function testTheOverviewListsTheLabelsAndANewOneCanBeAdded(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/bodies/labels',
        );
        $client->waitFor('table');

        $body = $client->getCrawler()->filter('tbody')->text();
        foreach (
            [
                'Education',
                'Career',
                'Personal development',
                'Fun',
                'FYC',
            ] as $name
        ) {
            self::assertStringContainsString(
                $name,
                $body,
            );
        }

        // The seed puts exactly one label on a page, so the usage the overview counts comes out of the join.
        self::assertStringContainsString(
            'used by 1 revision',
            $body,
        );

        $client->submitForm(
            'Add label',
            [
                'label[name][valueNL]' => 'Test pillar',
                'label[name][valueEN]' => 'Test pillar',
            ],
        );
        // The submit returns before Turbo replaces the page, and the crawler still holds the pre-replace document, so
        // wait for the new row rather than for the table that was already there. The timeout is in seconds.
        $client->waitForElementToContain(
            'tbody',
            'Test pillar',
            10,
        );
        self::assertStringContainsString(
            'Test pillar',
            $client->getCrawler()->filter('tbody')->text(),
        );
    }

    public function testLabelsCanBePickedOnThePageAndReachTheReview(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/bodies/5',
        );
        $client->waitFor('form[action$="/revise"], a[href$="/edit"]');

        // BAC's page is approved in the seed; only a draft is editable, so the first move is to start one.
        if (1 === $client->getCrawler()->filter('form[action$="/revise"]')->count()) {
            $client->submitForm('Revise the page');
            $client->waitFor('form[action$="/revise"], a[href$="/edit"]');
        }

        $client->request(
            'GET',
            '/en/admin/bodies/5/edit',
        );
        $client->waitFor('select[name*="labelIds"]');

        $picker = $client->getCrawler()->filter('select[name*="labelIds"]');
        self::assertCount(
            1,
            $picker,
        );

        $options = $picker->filter('option')->each(
            static fn ($option): string => (string) $option->text(),
        );
        foreach (
            [
                'Education',
                'Career',
                'Personal development',
                'Fun',
                'FYC',
            ] as $name
        ) {
            self::assertContains(
                $name,
                $options,
            );
        }

        // The retired label is offered nowhere, because BAC's page does not carry it.
        self::assertNotContains(
            'Corona period',
            $options,
        );
        self::assertStringContainsString(
            'Fun',
            $picker->filter('option[selected]')->text(),
        );

        // The add API of a select takes an element, so the option is found and selected rather than added.
        $client->executeScript(<<<'JS'
            const select = document.querySelector('select[name*="labelIds"]');
            for (const option of select.options) {
                if ('FYC' === option.text.trim()) {
                    option.selected = true;
                }
            }
        JS);

        // The submit returns before Turbo replaces the page, so each step waits for its own field before it is
        // submitted. The view page is waited on by its review link, because the edit page links to itself from the
        // language switch.
        $client->submitForm('Next');
        $client->waitFor('input[name$="[website]"]');
        $client->submitForm('Next');
        $client->waitFor('input[name*="logoFile"]');
        $client->submitForm('Save draft');

        // The wait checks the first element matching the locator, so a plain "a" would match a navigation link and
        // never the review link; target it by its href instead.
        $client->waitFor('a[href*="/approvals/"]');

        $client->click($client->getCrawler()->filterXPath('//a[contains(., "Open the review")]')->link());
        $client->waitFor('.review-pane');

        // The text of a filtered crawler is read from its first matched element, and the first .review-row__label on
        // the page is the empty cell of the language head, so the pane is read from the live DOM instead.
        $paneText = strval(
            $client->executeScript('return document.querySelector(".review-pane").textContent;'),
        );
        self::assertStringContainsString(
            'Labels',
            $paneText,
        );

        // The added label comes out in the added style, the one BAC already carried stays plain.
        $badgeText = strval($client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('.review-pane .badge-success'))
                .map(b => b.textContent.trim()).join(' | ');
        JS));
        self::assertStringContainsString(
            'FYC',
            $badgeText,
        );
        $plainText = strval($client->executeScript(<<<'JS'
            return Array.from(document.querySelectorAll('.review-pane .badge-tag'))
                .map(b => b.textContent.trim()).join(' | ');
        JS));
        self::assertStringContainsString(
            'Fun',
            $plainText,
        );
    }
}
