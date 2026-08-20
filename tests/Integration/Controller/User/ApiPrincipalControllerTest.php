<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\ApiPrincipalController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The bearer tokens the API is read with, and who holds them.
 *
 * Their pages were lost when the two applications were merged -- the controller survived and its templates did not,
 * so every one of its actions rendered a template that was not there. These render them.
 */
final class ApiPrincipalControllerTest extends DatabaseTestCase
{
    public function testTheListRenders(): void
    {
        $this->authenticate();

        self::assertStringContainsString(
            'API principals',
            $this->render($this->controller()->index()),
        );
    }

    public function testTheCreateFormRenders(): void
    {
        $this->authenticate();

        self::assertStringContainsString(
            'Create API token',
            $this->render($this->controller()->create(new Request())),
        );
    }

    private function render(Response $response): string
    {
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        return (string) $response->getContent();
    }

    private function controller(): ApiPrincipalController
    {
        return self::getContainer()->get(ApiPrincipalController::class);
    }

    private function authenticate(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            [
                UserRoles::Admin->value,
                UserRoles::DatabaseAdmin->value,
            ],
        ));

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
