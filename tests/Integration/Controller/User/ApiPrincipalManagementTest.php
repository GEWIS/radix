<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\ApiPrincipalController;
use App\Entity\Database\User\ApiPrincipal;
use App\Entity\User\Enums\ApiPermissions;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\User\ApiPrincipalRepository;
use App\Service\User\ApiPrincipalService;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Minting and retiring the bearer tokens the API is read with. The token is shown once, on the redirect that follows
 * creation, so the flash bag is part of what the action promises. Invoked directly; the routes are behind the
 * register's administrator at the HTTP layer.
 */
final class ApiPrincipalManagementTest extends DatabaseTestCase
{
    public function testCreatingAPrincipalStoresItAndShowsTheTokenOnce(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());

        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'description' => 'A token for the test suite.',
            'permissions' => [ApiPermissions::MembersR->value],
        ]);
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(ApiPrincipalController::class)->create($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );

        $principals = self::getContainer()->get(ApiPrincipalService::class)->findAll();
        $created = null;
        foreach ($principals as $principal) {
            if ('A token for the test suite.' !== $principal->description) {
                continue;
            }

            $created = $principal;
        }

        self::assertInstanceOf(
            ApiPrincipal::class,
            $created,
            'The submitted principal is expected to be stored.',
        );

        $flashes = self::flashBag($session)->get('info');
        self::assertNotSame(
            [],
            $flashes,
        );
        self::assertInstanceOf(
            TranslatableMessage::class,
            $flashes[0],
        );
        self::assertStringContainsString(
            'Your API token is',
            $flashes[0]->getMessage(),
        );
    }

    public function testEditingAPrincipalStoresTheNewDescription(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());
        $principal = $this->firstSeededPrincipal();

        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'description' => 'The renamed development token.',
            'permissions' => [ApiPermissions::MembersR->value],
        ]);
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(ApiPrincipalController::class)->edit(
            $request,
            self::idOf($principal),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'The renamed development token.',
            self::getContainer()->get(ApiPrincipalService::class)->find(self::idOf($principal))?->description,
        );
    }

    public function testAPrincipalThatIsGoneCannotBeEdited(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());

        $response = self::getContainer()->get(ApiPrincipalController::class)->edit(
            new Request(),
            999999,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        $flashes = self::flashBag($session)->get('warning');
        self::assertCount(
            1,
            $flashes,
        );
        self::assertInstanceOf(
            TranslatableMessage::class,
            $flashes[0],
        );
        self::assertStringContainsString(
            'Could not find',
            $flashes[0]->getMessage(),
        );
    }

    public function testRevokingAPrincipalMarksItRevoked(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());
        $principal = $this->firstSeededPrincipal();

        $response = self::getContainer()->get(ApiPrincipalController::class)->revoke(self::idOf($principal));

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertTrue($principal->isRevoked());
    }

    public function testAPrincipalThatIsGoneCannotBeRevoked(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());

        $response = self::getContainer()->get(ApiPrincipalController::class)->revoke(999999);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        $flashes = self::flashBag($session)->get('warning');
        self::assertCount(
            1,
            $flashes,
        );
        self::assertInstanceOf(
            TranslatableMessage::class,
            $flashes[0],
        );
        self::assertStringContainsString(
            'Could not find',
            $flashes[0]->getMessage(),
        );
    }

    private function firstSeededPrincipal(): ApiPrincipal
    {
        $repository = self::getContainer()->get(ApiPrincipalRepository::class);
        $principal = $repository->findOneBy([]);
        self::assertInstanceOf(
            ApiPrincipal::class,
            $principal,
            'The seed is expected to contain an API principal.',
        );

        return $principal;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): Request
    {
        $request = Request::create(
            '/en/users/api-principals',
            'POST',
            ['api_principal' => $body],
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
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [
                    UserRoles::Admin->value,
                    UserRoles::DatabaseAdmin->value,
                ],
            ),
        );
    }

    private static function idOf(ApiPrincipal $principal): int
    {
        $id = $principal->id;
        self::assertNotNull($id);

        return $id;
    }
}
