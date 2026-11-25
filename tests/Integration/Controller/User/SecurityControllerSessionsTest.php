<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\UserController;
use App\Entity\User\Enums\DeviceTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\Session;
use App\Entity\User\User;
use App\Repository\User\ExternalAppAuthenticationRepository;
use App\Repository\User\SessionRepository;
use App\Security\User\HandlerRegistry;
use App\Security\User\MfaPolicy;
use App\Security\User\SudoMode;
use App\Service\User\SecurityNotifier;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\RememberMe\ResponseListener;

/**
 * The device list on the member's security page and the three ways of ending a session from it. The current device is
 * the interesting one: ending it has to leave the browser signed out, which is a different response from ending
 * somebody else's. Invoked directly, with a remember-me cookie made by the application's own handler.
 */
final class SecurityControllerSessionsTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;

    public function testTheDeviceListRendersTheActiveSessions(): void
    {
        $this->authenticate();
        $this->sessionRow('device-to-show');
        $this->entityManager->flush();
        $request = $this->request('/en/user/security');
        $user = $this->user();

        $response = self::getContainer()->get(UserController::class)->security(
            $request,
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(MfaPolicy::class),
            self::getContainer()->get(ExternalAppAuthenticationRepository::class),
            self::getContainer()->get(SecurityNotifier::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Active session',
            (string) $response->getContent(),
        );
        self::assertStringContainsString(
            'device-to-show',
            (string) $response->getContent(),
        );
    }

    public function testEndingASessionThatIsNotThereOnlyWarns(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security');
        $user = $this->user();

        $response = self::getContainer()->get(UserController::class)->sessionTerminate(
            'no-such-series',
            $request,
            self::getContainer()->get(SudoMode::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'Session not found or already expired.',
            self::flashBag($request)->get('warning'),
        );
    }

    public function testEndingAnotherDeviceRedirectsWithASuccess(): void
    {
        $this->authenticate();
        $row = $this->sessionRow('other-device');
        $this->entityManager->flush();
        $request = $this->request('/en/user/security');
        $user = $this->user();

        $response = self::getContainer()->get(UserController::class)->sessionTerminate(
            'other-device',
            $request,
            self::getContainer()->get(SudoMode::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/security',
            self::redirectTarget($response),
        );
        self::assertContains(
            'Session terminated successfully.',
            self::flashBag($request)->get('success'),
        );
        self::assertNull($this->sessions()->findOneBySeries($row->series));
    }

    /**
     * Ending the session making the request signs the browser out rather than leaving it on a page that can no longer
     * be trusted.
     */
    public function testEndingTheCurrentDeviceSignsTheBrowserOut(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security');
        $user = $this->user();

        $handler = self::getContainer()->get(HandlerRegistry::class)->get('main');
        self::assertNotNull($handler);
        $handler->createRememberMeCookie($user);
        $cookie = $request->attributes->get(ResponseListener::COOKIE_ATTR_NAME);
        self::assertInstanceOf(
            Cookie::class,
            $cookie,
        );
        $request->cookies->set(
            $cookie->getName(),
            (string) $cookie->getValue(),
        );

        $series = $handler->getSeriesFromCookie($request);
        self::assertNotNull($series);

        $response = self::getContainer()->get(UserController::class)->sessionTerminate(
            $series,
            $request,
            self::getContainer()->get(SudoMode::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/login',
            self::redirectTarget($response),
        );
        self::assertNull($this->sessions()->findOneBySeries($series));
    }

    public function testEndingTheOtherSessionsSaysHowManyWereEnded(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security');

        $response = self::getContainer()->get(UserController::class)->sessionTerminateOthers(
            $request,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            '0 other sessions terminated.',
            self::flashBag($request)->get('success'),
        );
    }

    public function testEndingEverySessionSignsTheBrowserOut(): void
    {
        $this->authenticate();
        $this->sessionRow('first-device');
        $this->sessionRow('second-device');
        $this->entityManager->flush();
        $request = $this->request('/en/user/security');

        $response = self::getContainer()->get(UserController::class)->sessionTerminateAll(
            $request,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/login',
            self::redirectTarget($response),
        );
        self::assertSame(
            [],
            $this->sessions()->findAllByUserOnFirewall(
                '8030',
                'main',
            ),
        );
    }

    private function sessionRow(string $series): Session
    {
        $session = new Session();
        $session->series = $series;
        $session->hashedToken = 'hashed-' . $series;
        $session->signature = 'signature-' . $series;
        $session->signaturePropertiesHash = 'properties-' . $series;
        $session->firewallName = 'main';
        $session->userIdentifier = '8030';
        $session->createdAt = new DateTimeImmutable('-1 day');
        $session->expiresAt = new DateTimeImmutable('+30 days');
        $session->lastUsedAt = new DateTimeImmutable('-2 minutes');
        $session->userAgent = 'a user agent';
        $session->ipAddress = '192.0.2.10';
        $session->deviceType = DeviceTypes::Pc;
        $session->phpSessionId = 'php-' . $series;

        $this->entityManager->persist($session);

        return $session;
    }

    private function sessions(): SessionRepository
    {
        return self::getContainer()->get(SessionRepository::class);
    }

    private function request(string $uri): Request
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
        $session->start();

        return $session;
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
