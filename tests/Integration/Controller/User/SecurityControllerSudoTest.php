<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\UserController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Security\User\BackupCodeManager;
use App\Security\User\SudoMode;
use App\Security\User\SudoReplay;
use App\Tests\Integration\DatabaseTestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function bin2hex;

/**
 * Re-authentication in front of a guarded write. The grant is a Valkey key scoped to the firewall and the PHP session,
 * so each test starts one of its own; the rate limiters are reset first because they are counted per account.
 */
final class SecurityControllerSudoTest extends DatabaseTestCase
{
    private const string PASSWORD = 'gewiswebgewis';
    private const int MEMBER = 8030;

    public function testTheConfirmationPageRenders(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/sudo?next=/en/user/settings/privacy');

        $response = $this->confirm($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Confirm your identity',
            (string) $response->getContent(),
        );
    }

    public function testTheRightPasswordGrantsAccessAndReturnsToTheWrite(): void
    {
        $this->authenticate();
        $sudoMode = self::getContainer()->get(SudoMode::class);
        $request = $this->request('/en/user/sudo?next=/en/user/settings/privacy');
        $this->submitPassword(
            $request,
            self::PASSWORD,
        );

        $response = $this->confirm($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/user/settings/privacy',
            self::redirectTarget($response),
        );
        self::assertTrue($sudoMode->isActive());
    }

    public function testTheWrongPasswordIsRefusedWithoutAGrant(): void
    {
        $this->authenticate();
        $sudoMode = self::getContainer()->get(SudoMode::class);
        $request = $this->request('/en/user/sudo');
        $this->submitPassword(
            $request,
            'not-the-password',
        );

        $response = $this->confirm($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Incorrect credentials.',
            (string) $response->getContent(),
        );
        self::assertFalse($sudoMode->isActive());
    }

    /**
     * A grant already live on a GET is not a reason to send a write, but the address it returns to is still the one
     * that was asked for.
     */
    public function testAGrantThatIsAlreadyLiveSkipsThePrompt(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/sudo?next=/en/admin/members');
        self::getContainer()->get(SudoMode::class)->grant();

        $response = $this->confirm($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/en/admin/members',
            self::redirectTarget($response),
        );
    }

    public function testANextAddressOutsideTheSiteIsDiscarded(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/sudo?next=//evil.example/steal');
        $this->submitPassword(
            $request,
            self::PASSWORD,
        );

        $response = $this->confirm($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            '/',
            self::redirectTarget($response),
        );
    }

    private function confirm(Request $request): Response
    {
        return self::getContainer()->get(UserController::class)->confirmSudo(
            $request,
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(SudoMode::class),
            self::getContainer()->get(SudoReplay::class),
            self::getContainer()->get(TotpAuthenticatorInterface::class),
            self::getContainer()->get(BackupCodeManager::class),
            $this->limiter('limiter.sudo_confirm_ip'),
            $this->limiter('limiter.sudo_confirm_credentials'),
            $this->user(),
        );
    }

    private function submitPassword(
        Request $request,
        string $password,
    ): void {
        $request->setMethod(Request::METHOD_POST);
        $request->request->set(
            'sudo_confirm_form',
            [
                '_csrf_token' => 'csrf-token',
                'password' => $password,
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        $this->limiter('limiter.sudo_confirm_ip')->create((string) $request->getClientIp())->reset();
        $this->limiter('limiter.sudo_confirm_credentials')
            ->create(bin2hex($this->user()->getUserIdentifier()))
            ->reset();
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
