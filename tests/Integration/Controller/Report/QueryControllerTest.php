<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Report;

use App\Controller\Report\QueryController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Form\Report\QueryExportType;
use App\Repository\Database\SavedQueryRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function preg_quote;
use function sprintf;

final class QueryControllerTest extends DatabaseTestCase
{
    private const string SEEDED_QUERY = 'Underage members (18-)';

    public function testStoredQueriesAreListedUnderTheirCategory(): void
    {
        $this->authenticateSecretary();
        $request = $this->pushRequest();

        $response = self::getContainer()->get(QueryController::class)->index($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();

        self::assertStringContainsString(
            'BAC/BHV',
            $content,
        );
        self::assertStringContainsString(
            self::SEEDED_QUERY,
            $content,
        );
        self::assertStringContainsString(
            'picker-group',
            $content,
        );
        self::assertStringNotContainsString(
            'delete-stored-query',
            $content,
        );
    }

    public function testAStoredQueryThatIsOpenCanBeDeleted(): void
    {
        $this->authenticateSecretary();
        $request = $this->pushRequest();

        $savedQuery = self::getContainer()->get(SavedQueryRepository::class)->findByName(self::SEEDED_QUERY);
        self::assertNotNull(
            $savedQuery,
            'The seed is expected to contain a stored query.',
        );

        $id = $savedQuery->id;
        self::assertNotNull($id);

        $response = self::getContainer()->get(QueryController::class)->show(
            $request,
            $id,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'delete-stored-query',
            (string) $response->getContent(),
        );
    }

    public function testDeletingAStoredQueryTakesItOffTheRail(): void
    {
        $this->authenticateSecretary();
        $this->pushRequest();

        $repository = self::getContainer()->get(SavedQueryRepository::class);
        $savedQuery = $repository->findByName(self::SEEDED_QUERY);

        self::assertNotNull(
            $savedQuery,
            'The seed is expected to contain a stored query.',
        );

        $id = $savedQuery->id;
        self::assertNotNull($id);

        $response = self::getContainer()->get(QueryController::class)->delete($id);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull($repository->find($id));
    }

    public function testDeletingAQueryThatIsNotStoredIsNotFound(): void
    {
        $this->authenticateSecretary();
        $this->pushRequest();

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(QueryController::class)->delete(0);
    }

    /**
     * The export answers a submission with a file rather than with a page, which is what the opt-out below is for.
     */
    public function testTheExportAnswersASubmissionWithAFile(): void
    {
        $this->authenticateSecretary();

        $savedQuery = self::getContainer()->get(SavedQueryRepository::class)->findByName(self::SEEDED_QUERY);
        self::assertNotNull(
            $savedQuery,
            'The seed is expected to contain a stored query.',
        );

        $request = new Request(
            request: [
                $this->exportFormName() => [
                    'query' => $savedQuery->query,
                    'name' => $savedQuery->name,
                    'type' => 'csv',
                    '_csrf_token' => 'csrf-token',
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod(Request::METHOD_POST);
        $this->pushRequest($request);

        $response = self::getContainer()->get(QueryController::class)->index($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringStartsWith(
            'text/csv',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    /**
     * Turbo fetches a form submission and renders the response, and it renders neither a download nor anything else
     * that is not HTML. Fetched, this submission succeeded and the file was discarded unread, so the form states that
     * the browser submits it.
     */
    public function testTheExportFormIsNotFetchedByTurbo(): void
    {
        $this->authenticateSecretary();
        $request = $this->pushRequest();

        $savedQuery = self::getContainer()->get(SavedQueryRepository::class)->findByName(self::SEEDED_QUERY);
        self::assertNotNull(
            $savedQuery,
            'The seed is expected to contain a stored query.',
        );

        $id = $savedQuery->id;
        self::assertNotNull($id);

        // A stored query is run as it is opened, and the export is only offered once there is a result to export.
        $content = (string) self::getContainer()->get(QueryController::class)->show(
            $request,
            $id,
        )->getContent();

        self::assertMatchesRegularExpression(
            sprintf(
                '{<form[^>]*\bname="%s"[^>]*\bdata-turbo="false"}',
                preg_quote(
                    $this->exportFormName(),
                    '{',
                ),
            ),
            $content,
        );
    }

    /**
     * Read from the form rather than written out, because it is the name the submission above has to arrive under and
     * the attribute the assertion above matches on.
     */
    private function exportFormName(): string
    {
        return self::getContainer()->get('form.factory')->create(QueryExportType::class)->getName();
    }

    private function pushRequest(?Request $request = null): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request ??= new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function authenticateSecretary(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8002);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user that keeps the register.',
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
