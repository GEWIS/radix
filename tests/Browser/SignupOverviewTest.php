<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Symfony\Component\Panther\Client;

use function intval;
use function sprintf;
use function strval;

/**
 * Ticking rows in the sign-up admin table after "Select all shown".
 *
 * The selection is a live-component prop the client merges checkbox state into by strict string comparison against the
 * checkbox value attribute, so the ids the "Select all shown" action stores must be that attribute itself, i.e. the
 * id as a string; otherwise an untick removes nothing and the selection stays stuck. The kernel test pins the stored
 * form, this proves the round trip once the JavaScript has run.
 *
 * Activity #9 (the Gala) has one limited list with four sign-ups; 8010 created it, so the page and the component open
 * for that member without the board role. Signing in grants sudo mode for the session, which the component's
 * live-component endpoint checks. Nothing is written to the database: the selection is component state and the actions
 * are read-only.
 */
final class SignupOverviewTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    private const string SIGNUPS = '/en/admin/activities/9/signups';

    private const string CHECKBOX = 'input[type=checkbox][data-model=selected]';

    private const string EMAIL_BUTTON = 'button[data-live-action-param="openComposer"]:not([data-live-scope-param])';

    public function testUnselectingAfterSelectingAllShownRemovesTheSignup(): void
    {
        $client = static::createPantherClient();
        $this->signIn(
            $client,
            '8010',
        );
        $client->request(
            'GET',
            self::SIGNUPS,
        );

        $client->waitFor('button[data-live-action-param="selectShown"]');
        $client->executeScript(<<<'JS'
            document.querySelector('button[data-live-action-param="selectShown"]').click();
        JS);
        $client->waitForElementToContain(
            self::EMAIL_BUTTON,
            '4 selected',
        );
        self::assertSame(
            4,
            $this->checkedCount($client),
        );

        $value = $this->firstCheckedValue($client);
        $this->clickCheckbox(
            $client,
            $value,
        );
        $client->waitForElementToContain(
            self::EMAIL_BUTTON,
            '3 selected',
        );
        self::assertSame(
            3,
            $this->checkedCount($client),
        );
        self::assertFalse($this->isChecked($client, $value));

        // Tick it again, so a selection made after the untick still works.
        $this->clickCheckbox(
            $client,
            $value,
        );
        $client->waitForElementToContain(
            self::EMAIL_BUTTON,
            '4 selected',
        );
        self::assertSame(
            4,
            $this->checkedCount($client),
        );

        // Clear, so the next test in the class starts with an empty selection.
        $client->waitFor('button[data-live-action-param="clearAll"]');
        $client->executeScript(<<<'JS'
            document.querySelector('button[data-live-action-param="clearAll"]').click();
        JS);
        $client->waitForElementToContain(
            self::EMAIL_BUTTON,
            'Email sign-ups',
        );
        self::assertSame(
            0,
            $this->checkedCount($client),
        );
    }

    public function testCheckingAndUncheckingARowWorksWithoutSelectingAllShown(): void
    {
        $client = static::createPantherClient();
        $this->signIn(
            $client,
            '8010',
        );
        $client->request(
            'GET',
            self::SIGNUPS,
        );

        $value = $this->firstValue($client);

        $this->clickCheckbox(
            $client,
            $value,
        );
        $client->waitForElementToContain(
            self::EMAIL_BUTTON,
            '1 selected',
        );
        self::assertSame(
            1,
            $this->checkedCount($client),
        );

        $this->clickCheckbox(
            $client,
            $value,
        );
        $client->waitForElementToContain(
            self::EMAIL_BUTTON,
            'Email sign-ups',
        );
        self::assertSame(
            0,
            $this->checkedCount($client),
        );
    }

    private function checkedCount(Client $client): int
    {
        return intval($client->executeScript(
            'return document.querySelectorAll("' . self::CHECKBOX . ':checked").length;',
        ));
    }

    private function firstCheckedValue(Client $client): string
    {
        return strval($client->executeScript(
            'return document.querySelector("' . self::CHECKBOX . ':checked").value;',
        ));
    }

    private function firstValue(Client $client): string
    {
        return strval($client->executeScript(
            'return document.querySelector("' . self::CHECKBOX . '").value;',
        ));
    }

    private function isChecked(
        Client $client,
        string $value,
    ): bool {
        return (bool) $client->executeScript(sprintf(
            <<<'JS'
                return document.querySelector('input[type=checkbox][data-model=selected][value="%s"]').checked;
            JS,
            $value,
        ));
    }

    private function clickCheckbox(
        Client $client,
        string $value,
    ): void {
        $client->executeScript(sprintf(
            <<<'JS'
                document.querySelector('input[type=checkbox][data-model=selected][value="%s"]').click();
            JS,
            $value,
        ));
    }
}
