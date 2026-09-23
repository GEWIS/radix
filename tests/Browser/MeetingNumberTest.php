<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Repository\Database\MeetingRepository;
use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Symfony\Component\Panther\Client;

use function sprintf;

final class MeetingNumberTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testTheNextNumberIsFilledInForTheTypeChosen(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/meetings/create',
        );

        $latestBm = $this->latest(MeetingTypes::BV);
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            sprintf(
                'BM %d',
                $latestBm->getNumber(),
            ),
        );

        self::assertSame(
            (string) ($latestBm->getNumber() + 1),
            $this->number($client),
        );
        self::assertStringNotContainsString(
            'Not recorded yet',
            $client->getCrawler()->filter('[data-meeting-number-target="hint"]')->text(),
        );

        $latestGmm = $this->latest(MeetingTypes::ALV);
        $this->choose(
            $client,
            MeetingTypes::ALV,
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            sprintf(
                'GMM %d',
                $latestGmm->getNumber(),
            ),
        );

        self::assertSame(
            (string) ($latestGmm->getNumber() + 1),
            $this->number($client),
        );
    }

    public function testANumberTypedByHandIsKeptWhenTheTypeChanges(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/meetings/create',
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            'BM',
        );

        $client->executeScript(<<<'JS'
            const number = document.querySelector('[data-meeting-number-target="number"]');
            number.value = '42';
            number.dispatchEvent(new Event('input', { bubbles: true }));
        JS);
        $this->choose(
            $client,
            MeetingTypes::VV,
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            'CM',
        );

        self::assertSame(
            '42',
            $this->number($client),
        );
    }

    public function testADateBeforeTheLatestMeetingIsPointedOut(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/meetings/create',
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            'BM',
        );

        $latest = $this->latest(MeetingTypes::BV);
        $this->date(
            $client,
            $latest->date->modify('-1 day')->format('Y-m-d'),
        );
        $client->waitForVisibility('[data-meeting-number-target="dateWarning"]');

        self::assertStringContainsString(
            sprintf(
                'BM %d was held on',
                $latest->getNumber(),
            ),
            $client->getCrawler()->filter('[data-meeting-number-target="dateWarning"]')->text(),
        );

        $this->date(
            $client,
            $latest->date->modify('+1 day')->format('Y-m-d'),
        );
        $client->waitForInvisibility('[data-meeting-number-target="dateWarning"]');

        self::assertSelectorNotExists('[data-meeting-number-target="dateWarning"]:not([hidden])');
    }

    public function testAnEarlierDateIsExpectedForALowerNumber(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/meetings/create',
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            'BM',
        );

        $latest = $this->latest(MeetingTypes::BV);
        $this->date(
            $client,
            $latest->date->modify('-1 day')->format('Y-m-d'),
        );
        $client->waitForVisibility('[data-meeting-number-target="dateWarning"]');

        $client->executeScript(sprintf(
            <<<'JS'
                const number = document.querySelector('[data-meeting-number-target="number"]');
                number.value = '%d';
                number.dispatchEvent(new Event('input', { bubbles: true }));
            JS,
            $latest->getNumber() - 1,
        ));
        $client->waitForInvisibility('[data-meeting-number-target="dateWarning"]');

        self::assertSelectorNotExists('[data-meeting-number-target="dateWarning"]:not([hidden])');
    }

    public function testAVirtualMeetingIsNotPointedOutForBeingBackdated(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/meetings/create',
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            'BM',
        );

        $latest = $this->latest(MeetingTypes::VIRT);
        $this->choose(
            $client,
            MeetingTypes::VIRT,
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            sprintf(
                'VIRT %d',
                $latest->getNumber(),
            ),
        );
        $this->date(
            $client,
            $latest->date->modify('-1 year')->format('Y-m-d'),
        );

        self::assertSame(
            $latest->date->modify('-1 year')->format('Y-m-d'),
            $client->executeScript('return document.querySelector(\'[data-meeting-number-target="date"]\').value;'),
        );
        self::assertSelectorNotExists('[data-meeting-number-target="dateWarning"]:not([hidden])');
    }

    /**
     * The seed has no skipped numbers, so one is written into the data island.
     */
    public function testASkippedNumberCanBePickedInstead(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/meetings/create',
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            'BM',
        );

        $latest = $this->latest(MeetingTypes::BV);
        $client->executeScript(sprintf(
            <<<'JS'
                const form = document.querySelector('[data-controller~="meeting-number"]');
                const island = JSON.parse(form.dataset.meetingNumberSuggestionsValue);
                island.BV.missing = [%d];
                form.dataset.meetingNumberSuggestionsValue = JSON.stringify(island);
            JS,
            $latest->getNumber() - 1,
        ));
        $this->choose(
            $client,
            MeetingTypes::BV,
        );
        $client->waitFor('[data-meeting-number-target="hint"] button');

        $client->getCrawler()->filter('[data-meeting-number-target="hint"] button')->click();

        self::assertSame(
            (string) ($latest->getNumber() - 1),
            $this->number($client),
        );

        $this->choose(
            $client,
            MeetingTypes::ALV,
        );
        $client->waitForElementToContain(
            '[data-meeting-number-target="hint"]',
            'GMM',
        );

        self::assertSame(
            (string) ($latest->getNumber() - 1),
            $this->number($client),
        );
    }

    private function latest(MeetingTypes $type): Meeting
    {
        $meeting = self::getContainer()->get(MeetingRepository::class)->findLatestOfType($type);

        self::assertNotNull(
            $meeting,
            'The seed is expected to contain a meeting of every type.',
        );

        return $meeting;
    }

    private function number(Client $client): string
    {
        return (string) $client->executeScript(
            'return document.querySelector(\'[data-meeting-number-target="number"]\').value;',
        );
    }

    private function choose(
        Client $client,
        MeetingTypes $type,
    ): void {
        $client->executeScript(sprintf(
            <<<'JS'
                const type = document.querySelector('[data-meeting-number-target="type"]');
                type.value = '%s';
                type.dispatchEvent(new Event('change', { bubbles: true }));
            JS,
            $type->value,
        ));
    }

    private function date(
        Client $client,
        string $date,
    ): void {
        $client->executeScript(sprintf(
            <<<'JS'
                const date = document.querySelector('[data-meeting-number-target="date"]');
                date.value = '%s';
                date.dispatchEvent(new Event('input', { bubbles: true }));
            JS,
            $date,
        ));
    }
}
