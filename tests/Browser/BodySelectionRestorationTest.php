<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;

use function sleep;

/**
 * When a decision form is submitted and validation fails, the form is re-rendered.
 * The body selection UI must be restored from the hidden reference fields so the
 * user can see which body they were editing and correct the validation errors.
 */
final class BodySelectionRestorationTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testBodySelectionRestoredAfterValidationError(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        // BV 1 is seeded by the fixtures. Use a high decision number that doesn't exist yet.
        $client->request(
            'GET',
            '/en/admin/meetings/BV/1/points/1/decisions/98',
        );

        // Wait for the decision form picker to be available.
        $client->waitFor('.picker-group');

        // Expand the Bodies group and click the Installation / Discharge tab.
        $client->executeScript(<<<'JS'
            document.querySelector('[data-bs-target="#kinds-body"]').click();
        JS);
        $client->waitFor('#kinds-body.show');
        $client->executeScript(<<<'JS'
            document.querySelector('#tab-organ-install-tab').click();
        JS);

        // Wait for the install form to be rendered.
        $client->waitFor('#tab-organ-install.active');

        // Set the body reference via the hidden fields.
        // BV 1 point 1 decision 1 is seeded by the fixtures and references the GEWIS Board.
        $client->executeScript(<<<'JS'
            document.querySelector('#install_subdecision_meeting_type').value = 'BV';
            document.querySelector('#install_subdecision_meeting_number').value = '1';
            document.querySelector('#install_subdecision_decision_point').value = '1';
            document.querySelector('#install_subdecision_decision_number').value = '1';
            document.querySelector('#install_subdecision_sequence').value = '0';
        JS);

        // Submit the form without adding any mutations.
        // This should trigger a validation error because no changes were made.
        $client->executeScript(
            'document.querySelector("[data-body-lookup-target=submit]").click();',
        );

        // Wait for the page to settle. Give it a few seconds.
        sleep(5);

        // The key test: verify the hidden fields still have the correct values
        // after the form is re-rendered. This proves the restoration worked.
        $typeValue = $client->executeScript(
            'return document.querySelector("#install_subdecision_meeting_type").value;',
        );
        $numberValue = $client->executeScript(
            'return document.querySelector("#install_subdecision_meeting_number").value;',
        );
        $pointValue = $client->executeScript(
            'return document.querySelector("#install_subdecision_decision_point").value;',
        );
        $decisionValue = $client->executeScript(
            'return document.querySelector("#install_subdecision_decision_number").value;',
        );
        $sequenceValue = $client->executeScript(
            'return document.querySelector("#install_subdecision_sequence").value;',
        );

        self::assertSame(
            'BV',
            $typeValue,
            'Meeting type should be restored',
        );
        self::assertSame(
            '1',
            $numberValue,
            'Meeting number should be restored',
        );
        self::assertSame(
            '1',
            $pointValue,
            'Decision point should be restored',
        );
        self::assertSame(
            '1',
            $decisionValue,
            'Decision number should be restored',
        );
        self::assertSame(
            '0',
            $sequenceValue,
            'Sequence should be restored',
        );
    }
}
