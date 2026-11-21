<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\AdminController;
use App\Entity\User\CompanyUser;
use App\Entity\User\Enums\DeviceTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\Session;
use App\Entity\User\User;
use App\Repository\User\CompanyUserRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What the website's administrator reads and ends about an account's sign-ins: the device lists for members and for
 * company representatives, ending one session or all of them, and taking a second factor off an account whose owner
 * lost it. Invoked directly; the class-level ROLE_ADMIN guard is enforced at the HTTP layer.
 */
final class AdminUserSecurityTest extends DatabaseTestCase
{
    private const int SUBJECT = 8030;

    public function testTheAdminPagesRender(): void
    {
        $this->authenticate();
        $this->pushRequest();
        $controller = $this->controller();

        self::assertSame(
            Response::HTTP_OK,
            $controller->index()->getStatusCode(),
        );
        self::assertSame(
            Response::HTTP_OK,
            $controller->companyUsersIndex()->getStatusCode(),
        );
        self::assertSame(
            Response::HTTP_OK,
            $controller->securityLog()->getStatusCode(),
        );
    }

    public function testTheMemberDeviceListRenders(): void
    {
        $this->authenticate();
        $this->pushRequest();
        $this->sessionRow(
            $this->user(),
            'main',
            'member-series',
        );
        $this->entityManager->flush();

        $response = $this->controller()->userSessions(self::SUBJECT);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '8030',
            (string) $response->getContent(),
        );
    }

    public function testADeviceListForAMemberWhoIsNotOnFileIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->userSessions(999999);
    }

    public function testEndingOneMemberSessionRedirectsWithASuccess(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $row = $this->sessionRow(
            $this->user(),
            'main',
            'to-be-ended',
            'another-php-session',
        );
        $this->entityManager->flush();

        $response = $this->controller()->terminateUserSession(
            self::SUBJECT,
            'to-be-ended',
            $this->request(),
        );

        $this->assertRedirectsToMemberSessions($response);
        self::assertContains(
            'Session terminated.',
            self::flashBag($session)->get('success'),
        );
        self::assertNull($this->entityManager->getRepository(Session::class)->findOneBySeries($row->series));
    }

    public function testEndingASessionThatIsAlreadyGoneOnlyWarns(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->terminateUserSession(
            self::SUBJECT,
            'not-a-series',
            $this->request(),
        );

        $this->assertRedirectsToMemberSessions($response);
        self::assertContains(
            'Session not found or already expired.',
            self::flashBag($session)->get('warning'),
        );
    }

    public function testEndingAllMemberSessionsRemovesEveryDevice(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $this->sessionRow(
            $this->user(),
            'main',
            'device-one',
        );
        $this->sessionRow(
            $this->user(),
            'main',
            'device-two',
        );
        $this->entityManager->flush();

        $response = $this->controller()->terminateAllUserSessions(self::SUBJECT);

        $this->assertRedirectsToMemberSessions($response);
        self::assertNotSame(
            [],
            self::flashBag($session)->get('success'),
        );
        self::assertSame(
            [],
            $this->entityManager->getRepository(Session::class)->findAllByUserOnFirewall(
                '8030',
                'main',
            ),
        );
    }

    public function testTheCompanyRepresentativesSessionsAreListed(): void
    {
        $this->authenticate();
        $this->pushRequest();
        $companyUser = $this->companyUser();
        $this->sessionRow(
            $companyUser,
            'company',
            'company-series',
        );
        $this->entityManager->flush();

        $response = $this->controller()->companyUserSessions(self::idOf($companyUser));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $companyUser->company->name,
            (string) $response->getContent(),
        );
    }

    public function testASessionsPageForACompanyUserWhoIsNotOnFileIsNotFound(): void
    {
        $this->authenticate();
        $this->pushRequest();

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->companyUserSessions(999999);
    }

    public function testEndingOneCompanySessionRedirectsWithASuccess(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $companyUser = $this->companyUser();
        $row = $this->sessionRow(
            $companyUser,
            'company',
            'company-to-end',
            'another-company-php-session',
        );
        $this->entityManager->flush();

        $response = $this->controller()->terminateCompanyUserSession(
            self::idOf($companyUser),
            'company-to-end',
            $this->request(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'Session terminated.',
            self::flashBag($session)->get('success'),
        );
        self::assertNull($this->entityManager->getRepository(Session::class)->findOneBySeries($row->series));
    }

    public function testEndingACompanySessionThatIsAlreadyGoneOnlyWarns(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $companyUser = $this->companyUser();

        $response = $this->controller()->terminateCompanyUserSession(
            self::idOf($companyUser),
            'not-a-series',
            $this->request(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'Session not found or already expired.',
            self::flashBag($session)->get('warning'),
        );
    }

    public function testEndingAllCompanySessionsRemovesEveryDevice(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $companyUser = $this->companyUser();
        $this->sessionRow(
            $companyUser,
            'company',
            'company-one',
        );
        $this->sessionRow(
            $companyUser,
            'company',
            'company-two',
        );
        $this->entityManager->flush();

        $response = $this->controller()->terminateAllCompanyUserSessions(self::idOf($companyUser));

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNotSame(
            [],
            self::flashBag($session)->get('success'),
        );
        self::assertSame(
            [],
            $this->entityManager->getRepository(Session::class)->findAllByUserOnFirewall(
                $companyUser->getUserIdentifier(),
                'company',
            ),
        );
    }

    public function testResettingMfaOnAnAccountWithoutItOnlyWarns(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->resetUserMfa(self::SUBJECT);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'Multi-factor authentication is not enabled for this user.',
            self::flashBag($session)->get('warning'),
        );
    }

    public function testResettingMfaTakesTheSecondFactorOffAndEndsTheSessions(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $user = $this->user();
        $user->totpSecret = 'JBSWY3DPEHPK3PXP';
        $this->sessionRow(
            $user,
            'main',
            'mfa-device',
        );
        $this->entityManager->flush();

        $response = $this->controller()->resetUserMfa(self::SUBJECT);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'MFA reset and all sessions terminated.',
            self::flashBag($session)->get('success'),
        );
        self::assertNull($user->totpSecret);
        self::assertSame(
            [],
            $this->entityManager->getRepository(Session::class)->findAllByUserOnFirewall(
                '8030',
                'main',
            ),
        );
    }

    public function testResettingCompanyMfaOnAnAccountWithoutItOnlyWarns(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $companyUser = $this->companyUser();

        $response = $this->controller()->resetCompanyUserMfa(self::idOf($companyUser));

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'Multi-factor authentication is not enabled for this user.',
            self::flashBag($session)->get('warning'),
        );
    }

    public function testResettingCompanyMfaTakesTheSecondFactorOffAndEndsTheSessions(): void
    {
        $this->authenticate();
        $session = $this->pushRequest();
        $companyUser = $this->companyUser();
        $companyUser->totpSecret = 'JBSWY3DPEHPK3PXP';
        $this->sessionRow(
            $companyUser,
            'company',
            'company-mfa-device',
        );
        $this->entityManager->flush();

        $response = $this->controller()->resetCompanyUserMfa(self::idOf($companyUser));

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'MFA reset and all sessions terminated.',
            self::flashBag($session)->get('success'),
        );
        self::assertNull($companyUser->totpSecret);
    }

    private function assertRedirectsToMemberSessions(Response $response): void
    {
        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/users/' . self::SUBJECT . '/sessions',
            $response->getTargetUrl(),
        );
    }

    private function sessionRow(
        UserInterface $user,
        string $firewall,
        string $series,
        ?string $phpSessionId = null,
    ): Session {
        $session = new Session();
        $session->series = $series;
        $session->hashedToken = 'hashed-' . $series;
        $session->signature = 'signature-' . $series;
        $session->signaturePropertiesHash = 'properties-' . $series;
        $session->firewallName = $firewall;
        $session->userIdentifier = $user->getUserIdentifier();
        $session->createdAt = new DateTimeImmutable('-2 hours');
        $session->expiresAt = new DateTimeImmutable('+30 days');
        $session->lastUsedAt = new DateTimeImmutable('-5 minutes');
        $session->userAgent = 'a user agent';
        $session->ipAddress = '192.0.2.10';
        $session->deviceType = DeviceTypes::Pc;
        $session->phpSessionId = $phpSessionId ?? 'php-' . $series;

        $this->entityManager->persist($session);

        return $session;
    }

    private function user(): User
    {
        $user = $this->entityManager->getRepository(User::class)->find(self::SUBJECT);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function companyUser(): CompanyUser
    {
        $companyUser = self::getContainer()->get(CompanyUserRepository::class)->findOneBy([
            'email' => 'recruitment@nexunt.example.com',
        ]);
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
            'The seed is expected to contain a representative of Nexunt.',
        );

        return $companyUser;
    }

    private function controller(): AdminController
    {
        return self::getContainer()->get(AdminController::class);
    }

    private function request(): Request
    {
        $request = new Request();
        $request->setLocale('en');
        $request->setSession(self::getContainer()->get('session.factory')->createSession());

        return $request;
    }

    private function pushRequest(): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = $this->request();
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

    private static function idOf(CompanyUser $companyUser): int
    {
        $id = $companyUser->id;
        self::assertNotNull($id);

        return $id;
    }
}
