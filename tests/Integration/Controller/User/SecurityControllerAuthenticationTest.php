<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Controller\User\CompanyUserController;
use App\Controller\User\UserController;
use App\Message\User\PasswordResetRequestEmail;
use App\Service\Application\AltchaSolutionGuard;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Tito10047\AltchaBundle\Service\DriverKeyProviderInterface;

use function array_filter;
use function base64_encode;
use function bin2hex;
use function json_encode;

/**
 * The pages that get someone into an account: the sign-in form, the logout route that the firewall intercepts, and the
 * request for a password-reset link. Invoked directly, as the rest of the suite does; the firewall and its redirects
 * are exercised elsewhere.
 */
final class SecurityControllerAuthenticationTest extends DatabaseTestCase
{
    public function testTheSignInFormRenders(): void
    {
        $this->pushRequest('/en/user/login');

        $response = self::getContainer()->get(UserController::class)->loginAction(
            self::getContainer()->get(AuthenticationUtils::class),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Login',
            (string) $response->getContent(),
        );
    }

    public function testTheLogoutRouteIsInterceptedByTheFirewall(): void
    {
        $this->expectException(LogicException::class);
        self::expectExceptionMessageIsOrContains(
            'This method can be blank - it will be intercepted by the logout key on your firewall.',
        );

        self::getContainer()->get(UserController::class)->logoutAction();
    }

    public function testThePasswordResetRequestFormRendersForMembers(): void
    {
        $request = $this->pushRequest('/en/user/forgot-password');

        $response = self::getContainer()->get(UserController::class)->passwordResetRequest(
            $request,
            $this->limiter('limiter.password_reset_ip'),
            $this->limiter('limiter.password_reset_credentials'),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(AltchaSolutionGuard::class),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Membership number',
            $content,
        );
        self::assertStringContainsString(
            'Email address',
            $content,
        );
    }

    /**
     * A representative signs in with their address alone, so their form has no membership number to ask for.
     */
    public function testTheCompanyPasswordResetRequestFormHasNoMembershipNumber(): void
    {
        $request = $this->pushRequest('/en/company/forgot-password');

        $response = self::getContainer()->get(CompanyUserController::class)->passwordResetRequest(
            $request,
            $this->limiter('limiter.password_reset_ip'),
            $this->limiter('limiter.password_reset_credentials'),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(AltchaSolutionGuard::class),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringNotContainsString(
            'Membership number',
            (string) $response->getContent(),
        );
    }

    public function testAnEmptyResetRequestIsRejectedOnTheForm(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = Request::create(
            '/en/user/forgot-password',
            'POST',
            ['password_reset_request_form' => ['_csrf_token' => 'csrf-token']],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(UserController::class)->passwordResetRequest(
            $request,
            $this->limiter('limiter.password_reset_ip'),
            $this->limiter('limiter.password_reset_credentials'),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(AltchaSolutionGuard::class),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
    }

    public function testACompleteResetRequestIsQueuedAndConfirmed(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = Request::create(
            '/en/user/forgot-password',
            'POST',
            [
                'password_reset_request_form' => [
                    '_csrf_token' => 'csrf-token',
                    'email' => 'someone@example.com',
                    'membershipNumber' => '8030',
                    'security' => $this->solvedAltcha(),
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->server->set(
            'REMOTE_ADDR',
            '198.51.100.20',
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
        $this->resetLimiters(
            '198.51.100.20',
            '8030',
            'someone@example.com',
        );

        $response = self::getContainer()->get(UserController::class)->passwordResetRequest(
            $request,
            $this->limiter('limiter.password_reset_ip'),
            $this->limiter('limiter.password_reset_credentials'),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(AltchaSolutionGuard::class),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/forgot-password',
            self::redirectTarget($response),
        );
        self::assertNotSame(
            [],
            $session->getFlashBag()->get('success'),
        );

        $transport = self::getContainer()->get('messenger.transport.high_priority');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );
        self::assertNotEmpty(array_filter(
            $transport->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof PasswordResetRequestEmail,
        ));
    }

    public function testACompleteCompanyResetRequestIsQueuedAndConfirmed(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $email = 'recruitment@nexunt.example.com';
        $this->limiter('limiter.password_reset_ip')->create('198.51.100.23')->reset();
        $this->limiter('limiter.password_reset_credentials')->create(bin2hex($email))->reset();

        $request = Request::create(
            '/en/company/forgot-password',
            'POST',
            [
                'password_reset_request_form' => [
                    '_csrf_token' => 'csrf-token',
                    'email' => $email,
                    'security' => $this->solvedAltcha(),
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->server->set(
            'REMOTE_ADDR',
            '198.51.100.23',
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(CompanyUserController::class)->passwordResetRequest(
            $request,
            $this->limiter('limiter.password_reset_ip'),
            $this->limiter('limiter.password_reset_credentials'),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(AltchaSolutionGuard::class),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company/forgot-password',
            self::redirectTarget($response),
        );
        self::assertNotSame(
            [],
            $session->getFlashBag()->get('success'),
        );
    }

    public function testASolvedCaptchaCannotBeReplayed(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $payload = $this->solvedAltcha();

        $body = static fn (): array => [
            'password_reset_request_form' => [
                '_csrf_token' => 'csrf-token',
                'email' => 'someone@example.com',
                'membershipNumber' => '8030',
                'security' => $payload,
            ],
        ];
        $this->resetLimiters(
            '198.51.100.21',
            '8030',
            'someone@example.com',
        );

        foreach ([1, 2] as $attempt) {
            $request = Request::create(
                '/en/user/forgot-password',
                'POST',
                $body(),
            );
            $request->headers->set(
                'Sec-Fetch-Site',
                'same-origin',
            );
            $request->server->set(
                'REMOTE_ADDR',
                '198.51.100.21',
            );
            $request->setSession($session);
            self::getContainer()->get('request_stack')->push($request);

            $response = self::getContainer()->get(UserController::class)->passwordResetRequest(
                $request,
                $this->limiter('limiter.password_reset_ip'),
                $this->limiter('limiter.password_reset_credentials'),
                self::getContainer()->get(MessageBusInterface::class),
                self::getContainer()->get(AltchaSolutionGuard::class),
            );

            self::assertSame(
                Response::HTTP_FOUND,
                $response->getStatusCode(),
            );

            if (2 !== $attempt) {
                continue;
            }

            self::assertContains(
                'Please complete the verification again and resubmit.',
                $session->getFlashBag()->get('danger'),
            );
        }
    }

    public function testTooManyRequestsAreRefusedWithAWait(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $this->resetLimiters(
            '198.51.100.22',
            '8030',
            'someone@example.com',
        );
        $limiter = $this->limiter('limiter.password_reset_ip');
        for ($i = 0; $i < 5; $i++) {
            $limiter->create('198.51.100.22')->consume();
        }

        $request = Request::create(
            '/en/user/forgot-password',
            'POST',
            [
                'password_reset_request_form' => [
                    '_csrf_token' => 'csrf-token',
                    'email' => 'someone@example.com',
                    'membershipNumber' => '8030',
                    'security' => $this->solvedAltcha(),
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->server->set(
            'REMOTE_ADDR',
            '198.51.100.22',
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(UserController::class)->passwordResetRequest(
            $request,
            $this->limiter('limiter.password_reset_ip'),
            $this->limiter('limiter.password_reset_credentials'),
            self::getContainer()->get(MessageBusInterface::class),
            self::getContainer()->get(AltchaSolutionGuard::class),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNotSame(
            [],
            $session->getFlashBag()->get('danger'),
        );
    }

    private function resetLimiters(
        string $ip,
        string $membershipNumber,
        string $email,
    ): void {
        $this->limiter('limiter.password_reset_ip')->create($ip)->reset();
        $this->limiter('limiter.password_reset_credentials')
            ->create(bin2hex($membershipNumber . '-' . $email))
            ->reset();
    }

    private function solvedAltcha(): string
    {
        $algorithm = self::getContainer()
            ->get(DriverKeyProviderInterface::class)
            ->getAlgorithm();
        $altcha = new Altcha(
            hmacSignatureSecret: (string) self::getContainer()->getParameter('kernel.secret'),
        );
        $challenge = $altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $algorithm,
            cost: 1,
            counter: 5000,
            expiresAt: new DateTimeImmutable('+15 minutes'),
        ));
        $solution = $altcha->solveChallenge(new SolveChallengeOptions(
            challenge: $challenge,
            algorithm: $algorithm,
        ));

        self::assertNotNull(
            $solution,
            'The proof of work is expected to be solvable.',
        );

        return base64_encode((string) json_encode([
            'challenge' => $challenge->toArray(),
            'solution' => $solution->toArray(),
        ]));
    }

    private function limiter(string $service): RateLimiterFactory
    {
        $limiter = self::getContainer()->get($service);
        self::assertInstanceOf(
            RateLimiterFactory::class,
            $limiter,
        );

        return $limiter;
    }

    private function pushRequest(string $path): Request
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = Request::create(
            $path,
            'GET',
        );
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }
}
