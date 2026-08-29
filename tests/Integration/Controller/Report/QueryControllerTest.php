<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Report;

use App\Controller\Report\QueryController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\SavedQueryRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

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

        $id = $savedQuery->getId();
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

        $id = $savedQuery->getId();
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

    private function pushRequest(): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
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
