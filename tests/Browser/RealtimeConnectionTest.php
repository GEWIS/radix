<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

use function intval;

/**
 * The single connection the application keeps open, and what navigating does to it.
 *
 * One browser keeps one connection open for any number of tabs: the tab that acquires an exclusive Web Lock opens
 * it and forwards each message to the others over a BroadcastChannel. The element is mounted by the base layout, so
 * Turbo replaces it on every navigation and the controller is disconnected and connected again.
 *
 * Two failures are possible and neither is visible without running it. A connection could be left open and a second
 * one opened beside it, so a browser ends up with several; or the lock could be released and not reacquired, so
 * nothing is subscribed. Both are counted by intercepting the constructor: a leak is a difference that grows with
 * each navigation, and a connection that was never reopened is a difference of zero.
 *
 * In practice one is opened and none closed, because the election is requested on every render and only resolved on
 * the last: the connections in between are aborted before one is opened.
 */
final class RealtimeConnectionTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testExactlyOneConnectionRemainsOpenAcrossNavigations(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/',
        );
        $client->waitFor('[data-controller~="notifications"]');

        // Counted from the constructor rather than by inspecting the connection, because a closed EventSource is
        // still an object and only the count distinguishes the open ones.
        $client->executeScript(<<<'JS'
            window.__realtime = { opened: 0, closed: 0 };
            const Original = window.EventSource;

            window.EventSource = function (...args) {
                window.__realtime.opened += 1;

                const source = new Original(...args);
                const close = source.close.bind(source);
                source.close = () => {
                    window.__realtime.closed += 1;

                    return close();
                };

                return source;
            };
            window.EventSource.prototype = Original.prototype;
        JS);

        foreach (
            [
                '/en/activities',
                '/en/',
                '/en/activities',
            ] as $page
        ) {
            $client->executeScript('Turbo.visit("' . $page . '");');
            $client->waitFor('[data-controller~="notifications"]');
        }

        // The tab that acquires the lock opens exactly one; the rest subscribe to it rather than to the hub. A tab
        // that navigated away without closing its own would raise the count. The election and the connection are
        // asynchronous, so the count is read once it is non-zero.
        $client->wait()->until(
            static fn (): bool => 0 < intval($client->executeScript('return window.__realtime.opened;')),
        );

        self::assertSame(
            1,
            intval($client->executeScript('return window.__realtime.opened - window.__realtime.closed;')),
        );
    }
}
