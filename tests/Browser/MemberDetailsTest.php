<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

/**
 * A member's self-service details page: changing email, managing mailing lists, adding and removing addresses,
 * and opting in or out of the Supremum magazine.
 */
final class MemberDetailsTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    /**
     * A member opts in to receiving the Supremum magazine.
     */
    public function testMemberCanOptIntoSupremum(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/user/settings/details',
        );
        $client->waitFor('.panel');

        // The Supremum checkbox should be present
        self::assertSelectorExists('[name$="[supremum]"]');

        // Check the checkbox and submit
        $client->executeScript(<<<'JS'
            const checkbox = document.querySelector('[name$="[supremum]"]');
            if (!checkbox.checked) {
                checkbox.click();
            }
        JS);

        $client->submitForm('Save Supremum preference');
        $client->waitFor('.panel');

        // After submission, the checkbox should be checked
        $client->executeScript(<<<'JS'
            window.__supremumChecked = document.querySelector('[name$="[supremum]"]')?.checked;
        JS);

        self::assertTrue(
            $client->executeScript('return window.__supremumChecked;'),
            'The Supremum checkbox should be checked after opting in',
        );
    }

    /**
     * A member opts out of receiving the Supremum magazine.
     */
    public function testMemberCanOptOutOfSupremum(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/user/settings/details',
        );
        $client->waitFor('.panel');

        // Uncheck the checkbox if it's checked and submit
        $client->executeScript(<<<'JS'
            const checkbox = document.querySelector('[name$="[supremum]"]');
            if (checkbox.checked) {
                checkbox.click();
            }
        JS);

        $client->submitForm('Save Supremum preference');
        $client->waitFor('.panel');

        // After submission, the checkbox should be unchecked
        $client->executeScript(<<<'JS'
            window.__supremumChecked = document.querySelector('[name$="[supremum]"]')?.checked;
        JS);

        self::assertFalse(
            $client->executeScript('return window.__supremumChecked;'),
            'The Supremum checkbox should be unchecked after opting out',
        );
    }

    /**
     * A member's address is shown on the details page.
     */
    public function testMemberAddressIsShown(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/user/settings/details',
        );
        $client->waitFor('.panel');

        // The addresses section should be present
        self::assertSelectorExists('h3');

        // Check that at least one address type is shown (home, student, or mail)
        $client->executeScript(<<<'JS'
            window.__hasAddressType = document.querySelector('h4.h6') !== null;
        JS);

        self::assertTrue(
            $client->executeScript('return window.__hasAddressType;'),
            'At least one address type should be shown',
        );
    }

    /**
     * A member can see their membership details.
     */
    public function testMemberCanSeeMembershipDetails(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/user/settings/details',
        );
        $client->waitFor('.panel');

        // The membership details section should be present
        $client->executeScript(<<<'JS'
            window.__hasMembershipSection = Array.from(document.querySelectorAll('h3'))
                .some(h3 => h3.textContent.includes('Membership'));
        JS);

        self::assertTrue(
            $client->executeScript('return window.__hasMembershipSection;'),
            'The membership details section should be shown',
        );
    }
}
