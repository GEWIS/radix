<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\CompanyUserController;
use App\Controller\User\UserController;
use App\Entity\Decision\Member;
use App\Entity\User\CompanyUser;
use App\Entity\User\PasswordReset;
use App\Entity\User\User;
use App\Repository\User\CompanyUserRepository;
use App\Repository\User\PasswordResetRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Util\Application\SplitToken;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use function str_repeat;

/**
 * The reset link, in two stages: the emailed token is exchanged for a single-use hash, and the hash is bound to the
 * session so that the form can be submitted without the token in the address. Both firewalls are served by the same
 * abstract controller, so a representative's reset is worth exercising as well.
 */
final class SecurityControllerPasswordResetTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;
    private const string COMPANY_EMAIL = 'recruitment@nexunt.example.com';
    private const string NEW_PASSWORD = 'Correct-Horse-Battery-9';

    public function testAClaimedLinkIsExchangedForAOneUseHash(): void
    {
        $token = $this->memberReset();

        $response = self::getContainer()->get(UserController::class)->passwordResetClaim(
            $token,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/reset-password?th=',
            self::redirectTarget($response),
        );

        $parts = SplitToken::split($token);
        self::assertNotNull($parts);
        $reset = $this->resets()->findBySelector($parts['selector']);
        self::assertNotNull($reset);
        self::assertNotNull($reset->tempHash);
    }

    public function testALinkThatDoesNotExistIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(UserController::class)->passwordResetClaim(
            str_repeat(
                'a',
                32,
            ) . '.' . str_repeat(
                'b',
                64,
            ),
            $this->resets(),
        );
    }

    public function testAnExpiredLinkIsNotFound(): void
    {
        $token = $this->memberReset(new DateTimeImmutable('-1 minute'));

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(UserController::class)->passwordResetClaim(
            $token,
            $this->resets(),
        );
    }

    public function testSomeoneElsesResetLinkIsNotFound(): void
    {
        // A representative's reset is served by the company firewall, and must not open the member's form.
        $token = $this->companyReset();

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(UserController::class)->passwordResetClaim(
            $token,
            $this->resets(),
        );
    }

    public function testTheHashOpensTheFormAndBindsTheResetToTheSession(): void
    {
        $reset = $this->memberResetRow($this->memberReset());
        $reset->tempHash = 'a-temp-hash';
        $reset->tempHashExpiresAt = new DateTimeImmutable('+3 minutes');
        $this->entityManager->flush();

        $request = $this->getRequest(
            '/en/user/reset-password',
            ['th' => 'a-temp-hash'],
        );

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            $reset->id,
            $request->getSession()->get('_pwr_id_user'),
        );
    }

    public function testAnUnknownHashIsTreatedAsAStaleLink(): void
    {
        $request = $this->getRequest(
            '/en/user/reset-password',
            ['th' => 'no-such-hash'],
        );

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/forgot-password',
            self::redirectTarget($response),
        );
        self::assertContains(
            'Your password-reset link has expired or has already been used. Please request a new one.',
            self::flashBag($request)->get('warning'),
        );
    }

    public function testAnExpiredHashIsTreatedAsAStaleLink(): void
    {
        $reset = $this->memberResetRow($this->memberReset());
        $reset->tempHash = 'stale-hash';
        $reset->tempHashExpiresAt = new DateTimeImmutable('-1 minute');
        $this->entityManager->flush();

        $request = $this->getRequest(
            '/en/user/reset-password',
            ['th' => 'stale-hash'],
        );

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
    }

    public function testOpeningTheFormWithoutAHashAndWithoutASessionIsAStaleLink(): void
    {
        $request = $this->getRequest('/en/user/reset-password');

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/forgot-password',
            self::redirectTarget($response),
        );
    }

    public function testSubmittingANewPasswordResetsItAndEndsEverySession(): void
    {
        $reset = $this->memberResetRow($this->memberReset());
        $request = $this->postPassword(
            '/en/user/reset-password',
            $reset,
            '_pwr_id_user',
        );

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/user/login',
            self::redirectTarget($response),
        );

        $user = $this->entityManager->getRepository(User::class)->find(self::MEMBER);
        self::assertInstanceOf(
            User::class,
            $user,
        );
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid(
            $user,
            self::NEW_PASSWORD,
        ));
        self::assertNull($request->getSession()->get('_pwr_id_user'));
    }

    public function testAPasswordThatDoesNotMatchItsRepetitionIsRejected(): void
    {
        $reset = $this->memberResetRow($this->memberReset());
        $request = $this->postPassword(
            '/en/user/reset-password',
            $reset,
            '_pwr_id_user',
            'Something-Else-9',
        );

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertNotNull($request->getSession()->get('_pwr_id_user'));
    }

    public function testAResetThatExpiredWhileTheFormWasOpenIsAStaleLink(): void
    {
        $reset = $this->memberResetRow($this->memberReset(new DateTimeImmutable('-1 minute')));
        $request = $this->postPassword(
            '/en/user/reset-password',
            $reset,
            '_pwr_id_user',
        );

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull($request->getSession()->get('_pwr_id_user'));
    }

    public function testSubmittingWithoutASessionIsAStaleLink(): void
    {
        $request = $this->getRequest('/en/user/reset-password');
        $request->setMethod(Request::METHOD_POST);

        $response = self::getContainer()->get(UserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
    }

    public function testARepresentativesResetIsServedByTheCompanyFirewall(): void
    {
        $reset = $this->companyResetRow();
        $request = $this->postPassword(
            '/en/company/security',
            $reset,
            '_pwr_id_company_user',
        );
        // A new account is only activated by the reset the first time; this one already exists.
        $request->attributes->set(
            '_route',
            'company_user_password_reset',
        );

        $response = self::getContainer()->get(CompanyUserController::class)->passwordResetForm(
            $request,
            $this->resets(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );

        $companyUser = self::getContainer()
            ->get(CompanyUserRepository::class)
            ->loadUserByIdentifier(self::COMPANY_EMAIL);
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
        );
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid(
            $companyUser,
            self::NEW_PASSWORD,
        ));
    }

    private function memberReset(?DateTimeImmutable $expiresAt = null): string
    {
        return $this->persistReset(
            $expiresAt,
            $this->entityManager->getRepository(Member::class)->find(self::MEMBER),
        );
    }

    private function memberResetRow(string $token): PasswordReset
    {
        $parts = SplitToken::split($token);
        self::assertNotNull($parts);
        $reset = $this->resets()->findBySelector($parts['selector']);
        self::assertInstanceOf(
            PasswordReset::class,
            $reset,
        );

        return $reset;
    }

    private function companyReset(): string
    {
        return $this->persistReset(
            null,
            null,
            self::getContainer()->get(CompanyUserRepository::class)->loadUserByIdentifier(self::COMPANY_EMAIL),
        );
    }

    private function companyResetRow(): PasswordReset
    {
        return $this->memberResetRow($this->companyReset());
    }

    private function persistReset(
        ?DateTimeImmutable $expiresAt,
        ?Member $member = null,
        ?CompanyUser $companyUser = null,
    ): string {
        $split = SplitToken::generate(
            16,
            32,
            PasswordReset::HASH_ALGO,
        );

        $reset = new PasswordReset(
            $expiresAt ?? new DateTimeImmutable('+1 hour'),
            $split['selector'],
            $split['hashedToken'],
            $member,
            $companyUser,
        );
        $this->entityManager->persist($reset);
        $this->entityManager->flush();

        return $split['token'];
    }

    private function postPassword(
        string $path,
        PasswordReset $reset,
        string $sessionKey,
        string $second = self::NEW_PASSWORD,
    ): Request {
        $session = $this->session();
        $session->set(
            $sessionKey,
            $reset->id,
        );

        $request = Request::create(
            $path,
            'POST',
            [
                'set_password_form' => [
                    '_csrf_token' => 'csrf-token',
                    'plainPassword' => [
                        'first' => self::NEW_PASSWORD,
                        'second' => $second,
                    ],
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getRequest(
        string $path,
        array $query = [],
    ): Request {
        $request = Request::create(
            $path,
            'GET',
            $query,
        );
        $request->setLocale('en');
        $request->setSession($this->session());
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

    private function resets(): PasswordResetRepository
    {
        return self::getContainer()->get(PasswordResetRepository::class);
    }
}
