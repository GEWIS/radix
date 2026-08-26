<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Application;

use App\Controller\Application\QueueController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The queue admin controller, invoked directly (the codebase has no WebTestCase). The class-level admin and sudo
 * guards are enforced at the HTTP layer, so a direct call exercises the action body and its template.
 */
final class QueueControllerTest extends DatabaseTestCase
{
    public function testIndexRendersTheTransportsAndAnEmptyFailureList(): void
    {
        $this->authenticateAdmin();
        $this->pushRequest();

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Transports',
            $content,
        );
        // Under test every queue is in-memory and so uncountable, which is the row the page must not read as empty.
        self::assertStringContainsString(
            'Unknown',
            $content,
        );
        // The failure list is its own component now; the page renders it, so its empty state still shows here.
        self::assertStringContainsString(
            'Nothing has failed every one of its retries.',
            $content,
        );
    }

    private function controller(): QueueController
    {
        return self::getContainer()->get(QueueController::class);
    }

    private function pushRequest(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function authenticateAdmin(): void
    {
        $user = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a board member.',
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [UserRoles::Admin->value],
            ),
        );
    }
}
