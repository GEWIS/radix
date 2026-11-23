<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Tests\Browser\Support\SignsInThroughTheBrowser;
use Symfony\Component\Panther\Client;

use function sprintf;

final class ConfirmationModalTest extends BrowserTestCase
{
    use SignsInThroughTheBrowser;

    public function testDeletingAMemberOpensOnTheMemberPage(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/members/8030',
        );

        $client->waitFor('[data-bs-target="#modal-delete-member-8030"]');
        $this->openModal(
            $client,
            'modal-delete-member-8030',
        );
        $client->waitForVisibility('#modal-delete-member-8030.show');

        self::assertStringContainsString(
            '/en/admin/members/8030',
            $client->getCurrentURL(),
        );
        self::assertSame(
            '/en/admin/members/8030/delete',
            $client->getCrawler()->filter('#modal-delete-member-8030 form')->attr('action'),
        );

        $client->getCrawler()->filter('#modal-delete-member-8030 [data-bs-dismiss="modal"]')->click();
        $client->waitForInvisibility('#modal-delete-member-8030.show');
    }

    public function testRemovingAnAddressOpensOnTheMemberPage(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/members/8030',
        );

        $client->waitFor('[data-bs-target="#modal-remove-address-8030-home"]');
        $this->openModal(
            $client,
            'modal-remove-address-8030-home',
        );
        $client->waitForVisibility('#modal-remove-address-8030-home.show');

        self::assertStringContainsString(
            '/en/admin/members/8030',
            $client->getCurrentURL(),
        );
        self::assertSame(
            '/en/admin/members/8030/remove/address/home',
            $client->getCrawler()->filter('#modal-remove-address-8030-home form')->attr('action'),
        );

        $client->getCrawler()->filter('#modal-remove-address-8030-home [data-bs-dismiss="modal"]')->click();
        $client->waitForInvisibility('#modal-remove-address-8030-home.show');
    }

    public function testDeletingAMailingListOpensOnTheOverview(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/members/mailing-lists',
        );

        $client->waitFor('[data-bs-target="#modal-delete-mailing-list-announcements"]');
        $this->openModal(
            $client,
            'modal-delete-mailing-list-announcements',
        );
        $client->waitForVisibility('#modal-delete-mailing-list-announcements.show');

        self::assertStringContainsString(
            '/en/admin/members/mailing-lists',
            $client->getCurrentURL(),
        );
        self::assertSame(
            '/en/admin/members/mailing-lists/delete/announcements',
            $client->getCrawler()->filter('#modal-delete-mailing-list-announcements form')->attr('action'),
        );

        $client->getCrawler()->filter('#modal-delete-mailing-list-announcements [data-bs-dismiss="modal"]')->click();
        $client->waitForInvisibility('#modal-delete-mailing-list-announcements.show');
    }

    public function testExtendingAMembershipOpensOnTheMemberPage(): void
    {
        $client = static::createPantherClient();
        $this->signIn($client);

        $client->request(
            'GET',
            '/en/admin/members/8100',
        );

        $client->waitFor('[data-bs-target="#modal-extend-membership-8100"]');
        $this->openModal(
            $client,
            'modal-extend-membership-8100',
        );
        $client->waitForVisibility('#modal-extend-membership-8100.show');

        self::assertStringContainsString(
            '/en/admin/members/8100',
            $client->getCurrentURL(),
        );
        self::assertSame(
            '/en/admin/members/8100/edit/expiration',
            $client->getCrawler()->filter('#modal-extend-membership-8100 form')->attr('action'),
        );

        $client->getCrawler()->filter('#modal-extend-membership-8100 [data-bs-dismiss="modal"]')->click();
        $client->waitForInvisibility('#modal-extend-membership-8100.show');
    }

    private function openModal(
        Client $client,
        string $id,
    ): void {
        $client->executeScript(sprintf(
            'document.querySelector(\'[data-bs-target="#%s"]\').click();',
            $id,
        ));
    }
}
