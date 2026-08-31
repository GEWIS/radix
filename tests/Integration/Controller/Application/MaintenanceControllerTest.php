<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Application;

use App\Controller\Application\MaintenanceController;
use App\Entity\Application\Enums\MaintenanceStatus;
use App\Entity\Application\MaintenanceWindow;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The maintenance admin controller, invoked directly (the codebase has no WebTestCase). The class-level admin guard is
 * enforced at the HTTP layer, so a direct call exercises the action body and its template.
 */
final class MaintenanceControllerTest extends DatabaseTestCase
{
    public function testIndexRendersTheScheduledWindows(): void
    {
        $this->authenticateAdmin();
        $this->pushRequest();

        $window = new MaintenanceWindow();
        $window->setStatus(MaintenanceStatus::ReadOnly);
        $this->entityManager->persist($window);
        $this->entityManager->flush();

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Maintenance windows',
            (string) $response->getContent(),
        );
    }

    public function testAdminIsRemindedTheyAreBypassingReadOnlyMaintenance(): void
    {
        self::assertStringContainsString(
            'You are using administrator privileges to bypass maintenance mode; the website is read-only for everyone else.', // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
            $this->renderIndexDuring(MaintenanceStatus::ReadOnly),
        );
    }

    public function testAdminIsRemindedTheyAreBypassingFullMaintenance(): void
    {
        self::assertStringContainsString(
            'You are using administrator privileges to bypass maintenance mode; the website is closed to everyone else.', // phpcs:ignore Generic.Files.LineLength.TooLong -- user-visible strings should not be split
            $this->renderIndexDuring(MaintenanceStatus::Full),
        );
    }

    public function testAdminSeesTheUsualNoticeWhenNoMaintenanceIsInEffect(): void
    {
        $this->authenticateAdmin();
        $this->pushRequest();

        self::assertStringContainsString(
            'You are currently using administrator privileges to use the website!',
            (string) $this->controller()->index()->getContent(),
        );
    }

    public function testCreatePageRenders(): void
    {
        $this->authenticateAdmin();
        $this->pushRequest();

        $response = $this->controller()->create(new Request());

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Schedule maintenance',
            (string) $response->getContent(),
        );
    }

    private function renderIndexDuring(MaintenanceStatus $status): string
    {
        $this->authenticateAdmin();
        $this->pushRequest();

        $window = new MaintenanceWindow();
        $window->setStatus($status);
        $this->entityManager->persist($window);
        $this->entityManager->flush();

        return (string) $this->controller()->index()->getContent();
    }

    private function controller(): MaintenanceController
    {
        return self::getContainer()->get(MaintenanceController::class);
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
