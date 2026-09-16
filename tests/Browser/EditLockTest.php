<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_filter;
use function is_string;
use function str_contains;
use function strval;

/**
 * The edit lock a screen acquires, and its release.
 *
 * The release was sent from a `beforeunload` handler, which fires when a document is unloaded. Turbo replaces the
 * body without unloading the document, so a navigation away from an edit screen left the lock in place until the
 * server expired it, blocking other editors. It is released when the controller's element is removed instead.
 *
 * Asserted by intercepting `navigator.sendBeacon`, which sends the release: the document survives a Turbo
 * navigation, so what was recorded before the navigation is still readable after it.
 *
 * Every screen here edits a draft, because only a draft is editable and therefore only a draft is locked. The seed
 * contains one of each.
 */
final class EditLockTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    /**
     * @return array<string, array{string}>
     */
    public static function lockedScreens(): array
    {
        return [
            'an activity' => ['/en/admin/activities/45/edit'],
            'a body page' => ['/en/admin/bodies/14/edit'],
            'a company profile' => ['/en/admin/career/companies/4/edit'],
            'a vacancy' => ['/en/admin/career/vacancies/7/edit'],
        ];
    }

    #[DataProvider('lockedScreens')]
    public function testTheLockIsReleasedWhenNavigatingAwayFromTheScreen(string $screen): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            $screen,
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

        $client->executeScript('Turbo.visit("/en/admin");');
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

    /**
     * What the second user to open a screen is shown. Nothing else covers it, and it is the reason for the lock: two
     * users editing the same draft would overwrite each other without warning.
     */
    public function testASecondEditorSeesTheScreenIsTaken(): void
    {
        $holder = static::createPantherClient();
        $this->signIn($holder);
        $holder->request(
            'GET',
            '/en/admin/bodies/14/edit',
        );
        $holder->waitFor('[data-controller~="edit-lock"]');

        // A second browser as a different administrator: the lock is per editor, so the same user opening it again
        // would reacquire it.
        $second = static::createAdditionalPantherClient();
        $this->signIn(
            $second,
            '8001',
        );
        $second->request(
            'GET',
            '/en/admin/bodies/14/edit',
        );

        $second->waitForVisibility('.panel-heading h3');

        self::assertStringContainsString(
            'Already being edited',
            strval($second->getCrawler()->filter('body')->text()),
        );
    }
}
