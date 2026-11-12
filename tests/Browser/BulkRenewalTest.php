<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

/**
 * The bulk renewal form, which previews before it changes and whose buttons submit through a regular form
 * submission rather than Turbo, because the response is the page itself with the result on it.
 *
 * The confirm test writes to the ledger, which the run re-seeds; the others write nothing. The member it renews is
 * from the seed, where every membership runs to the day the seed is loaded, so it is renewable on the day the
 * suite runs.
 */
final class BulkRenewalTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testCancellingReturnsToTheAttentionOverview(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/members/bulk-renewal',
        );

        $client->waitFor('textarea[name$="[memberIds]"]');
        $client->getCrawler()->selectLink('Cancel')->click();

        $client->waitFor('//h2[contains(., "Members requiring attention")]');

        self::assertStringContainsString(
            '/en/admin/members/attention-needed',
            $client->getCurrentURL(),
        );
    }

    public function testCancellingAfterAPreviewStillReturnsToTheAttentionOverview(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/members/bulk-renewal',
        );

        $client->waitFor('textarea[name$="[memberIds]"]');

        $client->executeScript(<<<'JS'
            document.querySelector('textarea[name$="[memberIds]"]').value = '99999';
        JS);
        $client->getCrawler()->filter('input[name="bulk_member_renewal[membershipType]"][value="graduate"]')->click();

        // 99999 is not a member, so the preview shows it as unresolvable rather than changing anything.
        $client->executeScript(<<<'JS'
            document.getElementById('bulk_member_renewal_submit').click();
        JS);

        $client->waitFor('//h3[contains(., "Preview")]');

        $client->getCrawler()->selectLink('Cancel')->click();

        $client->waitFor('//h2[contains(., "Members requiring attention")]');

        self::assertStringContainsString(
            '/en/admin/members/attention-needed',
            $client->getCurrentURL(),
        );
    }

    public function testPreviewingThenConfirmingShowsTheResult(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/members/bulk-renewal',
        );

        $client->waitFor('textarea[name$="[memberIds]"]');

        $client->executeScript(<<<'JS'
            document.querySelector('textarea[name$="[memberIds]"]').value = '8030';
        JS);
        $client->getCrawler()->filter('input[name="bulk_member_renewal[membershipType]"][value="graduate"]')->click();

        // The preview is the page rendered by the submission, so the confirm button it offers is what says the
        // submission was not refused.
        $client->executeScript(<<<'JS'
            document.getElementById('bulk_member_renewal_submit').click();
        JS);

        $client->waitFor('//button[contains(., "Confirm changes")]');
        self::assertStringContainsString(
            'Ready to renew.',
            $client->getCrawler()->text(),
        );
        self::assertStringContainsString(
            '8030',
            $client->getCrawler()->filter('table')->text(),
        );

        $client->executeScript(<<<'JS'
            document.getElementById('bulk_member_renewal_intent').click();
        JS);

        $client->waitFor('.alert-success');
        self::assertStringContainsString(
            'Confirmed 1 membership renewal(s).',
            $client->getCrawler()->text(),
        );
        self::assertStringContainsString(
            'Renewed.',
            $client->getCrawler()->text(),
        );
        // The confirmed page withholds the button rather than offering the same confirmation again.
        self::assertStringNotContainsString(
            'Confirm changes',
            $client->getCrawler()->text(),
        );
    }
}
