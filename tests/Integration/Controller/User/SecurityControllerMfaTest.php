<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\UserController;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Security\User\BackupCodeManager;
use App\Security\User\MfaPolicy;
use App\Service\User\SecurityNotifier;
use App\Tests\Integration\DatabaseTestCase;
use OTPHP\TOTP;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_filter;
use function str_contains;

/**
 * Turning a second factor on and off from the member's own security page: the secret is shown once, the first code
 * confirms it, the backup codes are shown once, and every session is made to sign in again. Invoked directly; the
 * sudo requirement and role guards are enforced at the HTTP layer.
 */
final class SecurityControllerMfaTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    public function testTheSetupPageShowsASecretThatCanBeConfirmed(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security/mfa/enable');

        $response = $this->enable($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Enable TOTP-based MFA',
            (string) $response->getContent(),
        );

        $secret = $request->getSession()->get('_mfa_setup_secret');
        self::assertIsString($secret);
        self::assertStringContainsString(
            $secret,
            (string) $response->getContent(),
        );
    }

    public function testConfirmingTheFirstCodeEnablesMfaAndOffersTheBackupCodes(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security/mfa/enable');
        $request->getSession()->set(
            '_mfa_setup_secret',
            self::SECRET,
        );
        $this->submitCode(
            $request,
            $this->currentCode(),
        );

        $response = $this->enable($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/security/mfa/backup-codes',
            self::redirectTarget($response),
        );
        self::assertSame(
            self::SECRET,
            $this->user()->totpSecret,
        );
        self::assertNull($request->getSession()->get('_mfa_setup_secret'));
        self::assertNotSame(
            [],
            $request->getSession()->get('_mfa_pending_backup_codes'),
        );
    }

    public function testACodeThatDoesNotMatchIsRefused(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security/mfa/enable');
        $request->getSession()->set(
            '_mfa_setup_secret',
            self::SECRET,
        );
        $this->submitCode(
            $request,
            '000000',
        );

        $response = $this->enable($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertNull($this->user()->totpSecret);
    }

    public function testASubmissionWithoutASecretStartsOver(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security/mfa/enable');
        $this->submitCode(
            $request,
            $this->currentCode(),
        );

        $response = $this->enable($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/security/mfa/enable',
            self::redirectTarget($response),
        );
    }

    public function testAnAccountThatAlreadyHasMfaIsSentBackToSecurity(): void
    {
        $this->authenticate();
        $this->user()->totpSecret = self::SECRET;
        $this->entityManager->flush();

        $response = $this->enable($this->request('/en/user/security/mfa/enable'));

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/security',
            self::redirectTarget($response),
        );
    }

    public function testTheBackupCodesAreShownOnce(): void
    {
        $this->authenticate();
        $this->user()->totpSecret = self::SECRET;
        $this->entityManager->flush();

        $request = $this->request('/en/user/security/mfa/backup-codes');
        $request->getSession()->set(
            '_mfa_pending_backup_codes',
            [
                'aaaaaaaaaaaaaaaa',
                'bbbbbbbbbbbbbbbb',
            ],
        );

        $response = self::getContainer()->get(UserController::class)->mfaBackupCodes(
            $request,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'aaaaaaaaaaaaaaaa',
            (string) $response->getContent(),
        );
        self::assertSame(
            [],
            $request->getSession()->get(
                '_mfa_pending_backup_codes',
                [],
            ),
        );
    }

    public function testAskingForTheCodesAgainWarnsThatTheyWereShown(): void
    {
        $this->authenticate();
        $this->user()->totpSecret = self::SECRET;
        $this->entityManager->flush();

        $request = $this->request('/en/user/security/mfa/backup-codes');
        $response = self::getContainer()->get(UserController::class)->mfaBackupCodes(
            $request,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'Backup codes have already been shown. Regenerate to get a new set.',
            self::flashBag($request)->get('warning'),
        );
    }

    public function testTheBackupCodesAreNotOfferedWithoutMfa(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security/mfa/backup-codes');

        $response = self::getContainer()->get(UserController::class)->mfaBackupCodes(
            $request,
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/security',
            self::redirectTarget($response),
        );
    }

    public function testRegeneratingTheCodesInvalidatesTheOldOnes(): void
    {
        $this->authenticate();
        $user = $this->user();
        $user->totpSecret = self::SECRET;
        $this->entityManager->flush();

        $request = $this->request('/en/user/security/mfa/backup-codes/regenerate');

        $response = self::getContainer()->get(UserController::class)->mfaRegenerateBackupCodes(
            $request,
            self::getContainer()->get(BackupCodeManager::class),
            self::getContainer()->get(SecurityNotifier::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        $codes = $request->getSession()->get('_mfa_pending_backup_codes');
        self::assertIsArray($codes);
        self::assertNotEmpty($codes);
        self::assertNotNull($user->forceReloginAt);
    }

    public function testTheCodesCannotBeRegeneratedWithoutMfa(): void
    {
        $this->authenticate();
        $request = $this->request('/en/user/security/mfa/backup-codes/regenerate');

        $response = self::getContainer()->get(UserController::class)->mfaRegenerateBackupCodes(
            $request,
            self::getContainer()->get(BackupCodeManager::class),
            self::getContainer()->get(SecurityNotifier::class),
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/security',
            self::redirectTarget($response),
        );
    }

    public function testDisablingMfaTakesTheSecondFactorOff(): void
    {
        $this->authenticate();
        $user = $this->user();
        $user->totpSecret = self::SECRET;
        $this->entityManager->flush();
        $request = $this->request('/en/user/security/mfa/disable');

        $response = self::getContainer()->get(UserController::class)->mfaDisable(
            $request,
            new MfaPolicy(false),
            self::getContainer()->get(SecurityNotifier::class),
            $user,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull($user->totpSecret);
    }

    /**
     * A board member or administrator may not turn it off while their role requires it, even if they can still reach
     * the action; the requirement outranks the preference.
     */
    public function testItCannotBeDisabledWhileTheRoleRequiresIt(): void
    {
        $boardMember = $this->entityManager->getRepository(User::class)->find(8025);
        self::assertInstanceOf(
            User::class,
            $boardMember,
        );
        $boardMember->totpSecret = self::SECRET;
        $this->entityManager->flush();

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $boardMember,
            'main',
            [UserRoles::Board->value],
        ));
        $request = $this->request('/en/user/security/mfa/disable');

        $response = self::getContainer()->get(UserController::class)->mfaDisable(
            $request,
            new MfaPolicy(true),
            self::getContainer()->get(SecurityNotifier::class),
            $boardMember,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            self::SECRET,
            $boardMember->totpSecret,
        );
        self::assertNotSame(
            [],
            array_filter(
                self::flashBag($request)->get('danger'),
                static fn (string $message): bool => str_contains(
                    $message,
                    'cannot be disabled',
                ),
            ),
        );
    }

    private function enable(Request $request): Response
    {
        return self::getContainer()->get(UserController::class)->mfaEnable(
            $request,
            self::getContainer()->get(BackupCodeManager::class),
            self::getContainer()->get(SecurityNotifier::class),
            $this->user(),
        );
    }

    private function submitCode(
        Request $request,
        string $code,
    ): void {
        $request->setMethod(Request::METHOD_POST);
        $request->request->set(
            'mfa_enable_form',
            [
                '_csrf_token' => 'csrf-token',
                'code' => $code,
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
    }

    private function currentCode(): string
    {
        return TOTP::createFromSecret(self::SECRET)->now();
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
