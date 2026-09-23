<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Facebook\WebDriver\WebDriverKeys;
use Symfony\Component\Panther\Client;

use function intval;
use function strval;
use function usleep;

/**
 * The warning before a form with changes that were not saved is left.
 *
 * A Turbo Drive visit replaces the body without unloading the document, so `beforeunload` never fires on the
 * navigation these tests drive. What they assert is the `turbo:before-visit` half of
 * `assets/controllers/application/unsaved_changes_controller.ts`, and the form theme that attaches it.
 *
 * The news editor is the form under test because it is reached in one request and is not a stepper. Nothing is
 * submitted, so nothing is written on a server whose writes are not rolled back.
 */
final class UnsavedChangesTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    private const string EDITOR = '/en/admin/news/create';

    private const string INDEX = '/en/admin/news';

    private const string TITLE = 'input[name$="[valueEN]"]';

    public function testLeavingAChangedFormAsksFirst(): void
    {
        $client = $this->openEditor();
        $this->type(
            $client,
            'x',
        );

        $client->executeScript('Turbo.visit("' . self::INDEX . '");');
        $client->wait()->until(fn (): bool => 0 < $this->asked($client));
        // A visit that was not stopped would have changed the address by now.
        usleep(500000);

        self::assertSame(
            self::EDITOR,
            $this->path($client),
        );
    }

    public function testLeavingAChangedFormContinuesOnceItIsConfirmed(): void
    {
        $client = $this->openEditor();
        $client->executeScript('window.__leave = true;');
        $this->type(
            $client,
            'x',
        );

        $client->executeScript('Turbo.visit("' . self::INDEX . '");');
        $client->wait()->until(fn (): bool => self::EDITOR !== $this->path($client));

        self::assertSame(
            self::INDEX,
            $this->path($client),
        );
    }

    public function testLeavingAFormThatWasNotTouchedAsksNothing(): void
    {
        $client = $this->openEditor();

        $client->executeScript('Turbo.visit("' . self::INDEX . '");');
        $client->wait()->until(fn (): bool => self::EDITOR !== $this->path($client));

        self::assertSame(
            0,
            $this->asked($client),
        );
    }

    /**
     * The values are compared rather than whether anything was typed, which is the reason the controller reads
     * the form twice instead of setting a flag on the first keystroke.
     */
    public function testLeavingAFormWhoseChangeWasUndoneAsksNothing(): void
    {
        $client = $this->openEditor();
        $this->type(
            $client,
            'x',
        );
        $this->type(
            $client,
            WebDriverKeys::BACKSPACE,
        );

        $client->executeScript('Turbo.visit("' . self::INDEX . '");');
        $client->wait()->until(fn (): bool => self::EDITOR !== $this->path($client));

        self::assertSame(
            0,
            $this->asked($client),
        );
    }

    /**
     * The dialog is the browser's own and cannot be answered through WebDriver, so it is replaced by one that
     * counts how often it was opened and returns what the test put in `window.__leave`.
     *
     * The editor is waited for because it writes into the textarea it replaces, and a value written after the
     * values were first read counts as a change.
     */
    private function openEditor(): Client
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            self::EDITOR,
        );
        $client->waitFor('[data-controller~="unsaved-changes"]');
        $client->waitFor('.ck-editor');

        $client->executeScript(<<<'JS'
            window.__asked = 0;
            window.__leave = false;
            window.confirm = () => {
                window.__asked += 1;

                return true === window.__leave;
            };
        JS);

        return $client;
    }

    private function type(
        Client $client,
        string $keys,
    ): void {
        $client->getMouse()->clickTo(self::TITLE);
        $client->getKeyboard()->sendKeys($keys);
    }

    private function asked(Client $client): int
    {
        return intval($client->executeScript('return window.__asked;'));
    }

    private function path(Client $client): string
    {
        return strval($client->executeScript('return window.location.pathname;'));
    }
}
