<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\ListController;
use App\Entity\Database\MailingList;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Service\Database\MailingListService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The mailing lists a member can subscribe to, from the register. A list's name is its identity, which is what the
 * create and edit pages return to; a name that is not on file is a 404 rather than an empty form. Invoked directly,
 * mirroring the rest of the suite.
 */
final class ListControllerTest extends DatabaseTestCase
{
    /** A list the seed subscribes members to by default. */
    private const string SEEDED = 'activities';

    public function testTheOverviewRenders(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(ListController::class)->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Mailing Lists',
            (string) $response->getContent(),
        );
    }

    public function testTheCreateFormRenders(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(ListController::class)->create(new Request());

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Add New Mailing List',
            (string) $response->getContent(),
        );
    }

    public function testCreatingAListStoresItAndOpensItForEditing(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'name' => 'test-list',
            'nl_description' => 'Een testlijst voor de leden.',
            'en_description' => 'A test list for the members.',
            'onForm' => '1',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(ListController::class)->create($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/mailing-lists/edit/test-list',
            self::redirectTarget($response),
        );

        $list = $this->service()->getList('test-list');
        self::assertNotNull(
            $list,
            'The submitted list is expected to be stored.',
        );
        self::assertSame(
            'Een testlijst voor de leden.',
            $list->getNlDescription(),
        );
    }

    public function testAnUnnamedListIsRejected(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'name' => '',
            'nl_description' => 'te kort',
            'en_description' => '',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(ListController::class)->create($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertNull($this->service()->getList(''));
    }

    public function testTheEditFormOfASeededListRenders(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(ListController::class)->edit(
            new Request(),
            self::SEEDED,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Edit Mailing List',
            (string) $response->getContent(),
        );
    }

    public function testSavingAnEditedListStoresTheNewDescription(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'name' => self::SEEDED,
            'nl_description' => 'Een bijgewerkte beschrijving.',
            'en_description' => 'An updated description.',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(ListController::class)->edit(
            $request,
            self::SEEDED,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'Een bijgewerkte beschrijving.',
            $this->service()->getList(self::SEEDED)?->getNlDescription(),
        );
    }

    public function testAListThatIsNotOnFileCannotBeEdited(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(ListController::class)->edit(
            new Request(),
            'no-such-list',
        );
    }

    public function testDeletingAListRemovesIt(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        // A list no member is subscribed to yet: a seeded one is referenced from the subscription table.
        $list = new MailingList();
        $list->name = 'delete-me';
        $list->setNlDescription('Een lijst om te verwijderen.');
        $list->setEnDescription('A list to delete.');
        $list->onForm = true;
        $list->defaultSub = false;
        $ledger = self::getContainer()->get('doctrine')->getManager('database');
        $ledger->persist($list);
        $ledger->flush();

        $response = self::getContainer()->get(ListController::class)->delete('delete-me');

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull(self::getContainer()->get(MailingListService::class)->getList('delete-me'));
    }

    public function testAListThatIsNotOnFileCannotBeDeleted(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(ListController::class)->delete('no-such-list');
    }

    private function service(): MailingListService
    {
        return self::getContainer()->get(MailingListService::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): Request
    {
        $request = Request::create(
            '/en/admin/members/mailing-lists',
            'POST',
            ['mailing_list' => $body],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        return $request;
    }

    private function pushRequest(Request $request): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    private function authenticate(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8002);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [
                    UserRoles::Board->value,
                    UserRoles::DatabaseAdmin->value,
                ],
            ),
        );
    }
}
