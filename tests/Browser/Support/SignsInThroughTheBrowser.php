<?php

declare(strict_types=1);

namespace App\Tests\Browser\Support;

use Symfony\Component\Panther\Client;

use function is_string;

/**
 * Signs in by filling in the form, because {@see \App\Tests\Support\SignsInThroughTheKernel} authenticates through
 * container services in the test process and the browser is talking to a different one.
 *
 * The credentials are the seed's, read from the environment rather than written down again here. Member 8000 is an
 * administrator in the fixtures, and signing in grants sudo mode for as long as it lasts, so the administrative pages
 * are reachable afterwards without a second prompt. Every seeded member has the same password, so naming another one
 * is what a test needs to be two people at once.
 */
trait SignsInThroughTheBrowser
{
    private function signIn(
        Client $client,
        ?string $login = null,
    ): void {
        $client->request(
            'GET',
            '/en/user/login',
        );

        // One browser serves every test in a class, so a later one arrives already signed in and this page offers to
        // sign out rather than in.
        if (0 < $client->getCrawler()->filter('[href*="logout"]')->count()) {
            return;
        }

        $client->submitForm(
            'Sign in',
            [
                '_username' => $login ?? self::seededCredential('DEMO_CREDENTIALS_USERNAME'),
                '_password' => self::seededCredential('DEMO_CREDENTIALS_PASSWORD'),
            ],
        );

        // The form posts to itself and returns a redirect, so waiting for the sign-out link is what tells the
        // difference between having arrived and having been refused.
        $client->waitFor('[href*="logout"]');
    }

    /**
     * Read from `$_SERVER` rather than through `getenv()`: the bootstrap loads the environment with Symfony's Dotenv,
     * which fills `$_SERVER` and `$_ENV` and leaves the process environment alone.
     */
    private static function seededCredential(string $variable): string
    {
        $value = $_SERVER[$variable] ?? null;

        return is_string($value)
            ? $value
            : '';
    }
}
