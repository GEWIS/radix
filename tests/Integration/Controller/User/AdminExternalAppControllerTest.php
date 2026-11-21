<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\AdminExternalAppController;
use App\Entity\User\Enums\ExternalAppSignature;
use App\Entity\User\Enums\ExternalAppTokenDelivery;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\ExternalApp;
use App\Entity\User\User;
use App\Repository\User\ExternalAppRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The three-step form that registers and edits the applications allowed to authenticate members. A flow keeps its run
 * in the session and names the run in the address, so each step is a fresh request on the same session.
 */
final class AdminExternalAppControllerTest extends DatabaseTestCase
{
    private const string FLOW = 'test-run';

    public function testTheOverviewListsTheRegisteredApplications(): void
    {
        $this->authenticate();
        $this->pushRequest('/en/admin/users/apps');

        $response = $this->controller()->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'sudosos',
            (string) $response->getContent(),
        );
    }

    public function testOpeningTheCreateFormStartsARun(): void
    {
        $this->authenticate();
        $request = $this->request(
            '/en/admin/users/apps/create',
            'GET',
        );
        $request->attributes->set(
            '_route',
            'admin/users/apps/create',
        );
        $request->attributes->set(
            '_route_params',
            [],
        );

        $response = $this->controller()->create($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'flow=',
            self::redirectTarget($response),
        );
    }

    public function testTheFirstStepRenders(): void
    {
        $this->authenticate();
        $request = $this->request(
            '/en/admin/users/apps/create',
            'GET',
            ['flow' => self::FLOW],
        );

        $response = $this->controller()->create($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Application identifier',
            (string) $response->getContent(),
        );
    }

    public function testARejectedStepIsShownAgainWithItsErrors(): void
    {
        $this->authenticate();
        $request = $this->request(
            '/en/admin/users/apps/create',
            'POST',
            ['flow' => self::FLOW],
        );
        $request->request->set(
            'external_app_flow',
            [
                '_csrf_token' => 'csrf-token',
                'next' => '',
            ],
        );

        $response = $this->controller()->create($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
    }

    public function testWalkingTheStepsCreatesTheApplication(): void
    {
        $this->authenticate();
        $session = $this->pushRequest('/en/admin/users/apps/create');

        $first = $this->request(
            '/en/admin/users/apps/create',
            'POST',
            ['flow' => self::FLOW],
            $session,
        );
        $first->request->set(
            'external_app_flow',
            [
                '_csrf_token' => 'csrf-token',
                'application' => [
                    'appId' => 'test-app',
                    'callback' => 'https://test.example.test/callback',
                    'url' => 'https://test.example.test',
                ],
                'next' => '',
            ],
        );
        $this->assertRedirectsToTheFlow($this->controller()->create($first));

        $second = $this->request(
            '/en/admin/users/apps/create',
            'POST',
            ['flow' => self::FLOW],
            $session,
        );
        $second->request->set(
            'external_app_flow',
            [
                '_csrf_token' => 'csrf-token',
                'signing' => [
                    'signature' => ExternalAppSignature::HS512->value,
                    'tokenDelivery' => ExternalAppTokenDelivery::Query->value,
                    'secret' => 'a-secret-that-is-long-enough-for-hs512-signing',
                ],
                'next' => '',
            ],
        );
        $this->assertRedirectsToTheFlow($this->controller()->create($second));

        $third = $this->request(
            '/en/admin/users/apps/create',
            'POST',
            ['flow' => self::FLOW],
            $session,
        );
        $third->request->set(
            'external_app_flow',
            [
                '_csrf_token' => 'csrf-token',
                'claims' => [
                    'enabled' => '1',
                ],
                'finish' => '',
            ],
        );
        $response = $this->controller()->create($third);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/users/apps',
            self::redirectTarget($response),
        );

        $app = self::getContainer()->get(ExternalAppRepository::class)->findByAppId('test-app');
        self::assertInstanceOf(
            ExternalApp::class,
            $app,
        );
        self::assertSame(
            'https://test.example.test/callback',
            $app->callback,
        );
    }

    public function testTheEditFormRendersTheApplication(): void
    {
        $this->authenticate();
        $request = $this->request(
            '/en/admin/users/apps/1/edit',
            'GET',
            ['flow' => self::FLOW],
        );

        $response = $this->controller()->edit(
            $request,
            $this->app(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $this->app()->appId,
            (string) $response->getContent(),
        );
    }

    public function testOpeningTheEditFormStartsARun(): void
    {
        $this->authenticate();
        $request = $this->request(
            '/en/admin/users/apps/1/edit',
            'GET',
        );
        $request->attributes->set(
            '_route',
            'admin/users/apps/edit',
        );
        $request->attributes->set(
            '_route_params',
            ['externalApp' => $this->app()->id],
        );

        $response = $this->controller()->edit(
            $request,
            $this->app(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'flow=',
            self::redirectTarget($response),
        );
    }

    private function assertRedirectsToTheFlow(Response $response): void
    {
        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'flow=' . self::FLOW,
            self::redirectTarget($response),
        );
    }

    private function app(): ExternalApp
    {
        $app = self::getContainer()->get(ExternalAppRepository::class)->findByAppId('sudosos');
        self::assertInstanceOf(
            ExternalApp::class,
            $app,
        );

        return $app;
    }

    private function controller(): AdminExternalAppController
    {
        return self::getContainer()->get(AdminExternalAppController::class);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(
        string $uri,
        string $method,
        array $query = [],
        ?SessionInterface $session = null,
    ): Request {
        $session ??= $this->session();

        $request = Request::create(
            $uri,
            $method,
        );
        $request->query->replace($query);
        if ('POST' === $method) {
            $request->headers->set(
                'Sec-Fetch-Site',
                'same-origin',
            );
        }

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function pushRequest(string $uri): SessionInterface
    {
        $session = $this->session();
        $request = Request::create(
            $uri,
            'GET',
        );
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $session;
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
}
