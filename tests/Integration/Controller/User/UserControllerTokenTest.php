<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\UserController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\ExternalApp;
use App\Entity\User\ExternalAppAuthentication;
use App\Entity\User\User;
use App\Repository\User\ExternalAppRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Letting an external application authenticate as the member: the consent form, the fifteen-minutes-later reminder,
 * the decline that sends them back without a token, and the applications that may not be used at all.
 */
final class UserControllerTokenTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;
    private const string APP = 'sudosos';

    public function testAnApplicationThatIsNotRegisteredIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest('/en/user/token');

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->token(
            Request::create(
                '/user/token',
                'GET',
                ['app' => 'no-such-app'],
            ),
            'no-such-app',
        );
    }

    public function testADisabledApplicationMayNotBeUsed(): void
    {
        $this->authenticate();
        $this->pushRequest('/en/user/token');
        $app = $this->app();
        $app->enabled = false;
        $this->entityManager->flush();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->token(
            Request::create(
                '/user/token',
                'GET',
                ['app' => self::APP],
            ),
            self::APP,
        );
    }

    public function testTheConsentFormIsShownTheFirstTime(): void
    {
        $this->authenticate();
        $this->pushRequest('/en/user/token');

        $response = $this->controller()->token(
            Request::create(
                '/user/token',
                'GET',
                ['app' => self::APP],
            ),
            self::APP,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            self::APP,
            (string) $response->getContent(),
        );
    }

    public function testAuthorisingSendsTheBrowserToTheCallbackWithAToken(): void
    {
        $this->authenticate();
        $request = $this->pushRequest('/user/token');
        $request->setMethod(Request::METHOD_POST);
        $request->request->set(
            'external_app_authorisation',
            [
                '_csrf_token' => 'csrf-token',
                'authorise' => '',
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        $response = $this->controller()->token(
            $request,
            self::APP,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $this->app()->callback,
            (string) $response->getContent(),
        );
    }

    public function testDecliningSendsTheBrowserBackWithoutAToken(): void
    {
        $this->authenticate();
        $request = $this->pushRequest('/user/token');
        $request->setMethod(Request::METHOD_POST);
        $request->request->set(
            'external_app_authorisation',
            [
                '_csrf_token' => 'csrf-token',
                'cancel' => '',
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        $response = $this->controller()->token(
            $request,
            self::APP,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $this->app()->url,
            (string) $response->getContent(),
        );
    }

    /**
     * A recent authorisation is honoured without showing the form again.
     */
    public function testAnAuthorisationWithinNinetyDaysIsHonouredWithoutAPrompt(): void
    {
        $this->authenticate();
        $this->pushRequest('/en/user/token');
        $this->authentication(new DateTimeImmutable('-10 days'));

        $response = $this->controller()->token(
            Request::create(
                '/user/token',
                'GET',
                ['app' => self::APP],
            ),
            self::APP,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $this->app()->callback,
            (string) $response->getContent(),
        );
    }

    public function testAnOlderAuthorisationGetsTheLighterReminder(): void
    {
        $this->authenticate();
        $this->pushRequest('/en/user/token');
        $this->authentication(new DateTimeImmutable('-100 days'));

        $response = $this->controller()->token(
            Request::create(
                '/user/token',
                'GET',
                ['app' => self::APP],
            ),
            self::APP,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Continue',
            (string) $response->getContent(),
        );
    }

    private function authentication(DateTimeImmutable $time): void
    {
        $authentication = new ExternalAppAuthentication();
        $authentication->user = $this->user();
        $authentication->externalApp = $this->app();
        $authentication->time = $time;
        $this->entityManager->persist($authentication);
        $this->entityManager->flush();
    }

    private function app(): ExternalApp
    {
        $app = self::getContainer()->get(ExternalAppRepository::class)->findByAppId(self::APP);
        self::assertInstanceOf(
            ExternalApp::class,
            $app,
        );

        return $app;
    }

    private function controller(): UserController
    {
        return self::getContainer()->get(UserController::class);
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(self::MEMBER);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function pushRequest(string $uri): Request
    {
        $session = $this->session();

        $request = Request::create(
            $uri,
            'GET',
        );
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function session(): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        return $session;
    }

    private function authenticate(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $this->user(),
                'main',
                [UserRoles::User->value],
            ),
        );
    }
}
