<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

use function array_filter;
use function is_string;
use function str_contains;

/**
 * The lock a screen holds while it is being edited, and letting go of it.
 *
 * This was released from a `beforeunload` handler, which fires when a document is torn down. Turbo replaces the body
 * without tearing the document down, so on every navigation away from an edit screen the lock would have been held
 * until the server expired it, and nobody else could edit in the meantime. It is released when the controller's
 * element goes away instead.
 *
 * Asserted by watching `navigator.sendBeacon`, which is what carries the release: the document survives a Turbo
 * navigation, so what was recorded before leaving can still be read afterwards.
 */
final class EditLockTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    /**
     * Only a draft is edited, and the seed holds two of them.
     */
    private const string DRAFT_ACTIVITY = '/en/admin/activities/45/edit';

    public function testTheLockIsReleasedWhenNavigatingAwayFromTheScreen(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            self::DRAFT_ACTIVITY,
        );
        $client->waitFor('[data-controller~="edit-lock"]');

        $client->executeScript(<<<'JS'
            window.__beacons = [];
            const send = navigator.sendBeacon.bind(navigator);
            navigator.sendBeacon = (url, data) => {
                window.__beacons.push(String(url));

                return send(url, data);
            };
        JS);

        $client->executeScript('Turbo.visit("/en/admin/activities");');
        $client->waitForInvisibility('[data-controller~="edit-lock"]');

        self::assertNotEmpty(
            array_filter(
                (array) $client->executeScript('return window.__beacons;'),
                static fn (mixed $url): bool => is_string($url) && str_contains(
                    $url,
                    '/edit/release',
                ),
            ),
        );
    }
}
