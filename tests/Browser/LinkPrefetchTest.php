<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Symfony\Component\Panther\Client;

use function intval;
use function strval;
use function usleep;

/**
 * Turbo fetches a link while the pointer is over it, and renders that response when the link is clicked. Such a
 * fetch is not the page to return to after signing in, and a link whose GET has a side effect is marked so that
 * it is not prefetched.
 */
final class LinkPrefetchTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    private const string PROTECTED = '/en/admin/pages';

    public function testCrossingAProtectedLinkDoesNotChangeWhereSigningInLeads(): void
    {
        $client = static::createPantherClient();
        $this->signOut($client);
        $this->crossProtectedLink($client);

        $client->executeScript('Turbo.visit("/en/user/login");');
        $client->waitFor('input[name="_username"]');
        $this->submitCredentials($client);

        self::assertSame(
            '/en/',
            $this->path($client),
        );
    }

    public function testClickingAProtectedLinkAfterCrossingItStillLeadsBackToIt(): void
    {
        $client = static::createPantherClient();
        $this->signOut($client);
        $this->crossProtectedLink($client);

        $client->getMouse()->clickTo('#probe');
        $client->waitFor('input[name="_username"]');
        $this->submitCredentials($client);

        self::assertSame(
            self::PROTECTED,
            $this->path($client),
        );
    }

    public function testCrossingTheLogoutLinkDoesNotSignOut(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        // Started from a page that is neither outcome, so the path read afterwards is where the visit ended.
        $client->request(
            'GET',
            '/en/',
        );
        $client->executeScript('document.getElementById("gws-member").click();');
        $client->waitFor('a[href*="logout"]');
        $client->getMouse()->mouseMoveTo('a[href*="logout"]');
        // A prefetch would start 100ms after the pointer enters the link, so this waits longer than that.
        usleep(500000);

        $client->executeScript('Turbo.visit("/en/user/security");');
        $client->wait()->until(
            fn (): bool => '/en/' !== $this->path($client),
        );

        self::assertSame(
            '/en/user/security',
            $this->path($client),
        );
    }

    private function signOut(Client $client): void
    {
        $client->request(
            'GET',
            '/en/user/logout',
        );
    }

    private function crossProtectedLink(Client $client): void
    {
        $client->request(
            'GET',
            '/en',
        );
        $client->executeScript(<<<'JS'
            window.__prefetched = 0;
            document.addEventListener('turbo:before-fetch-response', (event) => {
                if (event.target && event.target.id === 'probe') {
                    window.__prefetched++;
                }
            });
            document.body.insertAdjacentHTML(
                'beforeend',
                '<a id="probe" href="/en/admin/pages" '
                    + 'style="position:fixed;top:0;left:0;z-index:9999;padding:1rem">probe</a>'
            );
        JS);
        $client->getMouse()->mouseMoveTo('#probe');
        $client->wait()->until(
            static fn (): bool => 0 < intval($client->executeScript('return window.__prefetched;')),
        );
    }

    private function submitCredentials(Client $client): void
    {
        $client->submitForm(
            'Sign in',
            [
                '_username' => self::seededCredential('DEMO_CREDENTIALS_USERNAME'),
                '_password' => self::seededCredential('DEMO_CREDENTIALS_PASSWORD'),
            ],
        );
        $client->waitFor('[href*="logout"]');
    }

    private function path(Client $client): string
    {
        return strval($client->executeScript('return window.location.pathname;'));
    }
}
