<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\User;

use App\Controller\User\CompanyInviteController;
use App\Entity\Career\Company;
use App\Entity\User\CompanyUser;
use App\Entity\User\CompanyUserInvite;
use App\Repository\Career\CompanyRepository;
use App\Repository\User\CompanyUserRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Util\Application\SplitToken;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use function str_repeat;

/**
 * Turning an invitation into an account. The invitation identifies the person, so the page is reachable without
 * signing in; an account created for a company without a running contract is told so instead of being signed in.
 */
final class CompanyInviteControllerTest extends DatabaseTestCase
{
    private const string PASSWORD = 'Correct-Horse-Battery-9';

    public function testAnInvitationThatIsNotValidSaysSo(): void
    {
        $request = $this->request(
            '/en/company/invite/x',
            'GET',
        );

        $response = $this->controller()->accept(
            str_repeat(
                'a',
                32,
            ) . '.' . str_repeat(
                'b',
                64,
            ),
            $request,
            self::getContainer()->get(Security::class),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company/login',
            self::redirectTarget($response),
        );
        self::assertContains(
            'This invitation is no longer valid. Ask your contact at GEWIS for a new one.',
            self::flashBag($request)->get('warning'),
        );
    }

    public function testAnExpiredInvitationSaysSo(): void
    {
        $request = $this->request(
            '/en/company/invite/x',
            'GET',
        );
        $invite = $this->invite(
            $this->company('nexunt'),
            new DateTimeImmutable('-1 day'),
        );

        $response = $this->controller()->accept(
            $invite['token'],
            $request,
            self::getContainer()->get(Security::class),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company/login',
            self::redirectTarget($response),
        );
    }

    public function testAValidInvitationOpensTheForm(): void
    {
        $invite = $this->invite($this->company('nexunt'));
        $request = $this->request(
            '/en/company/invite/x',
            'GET',
            ['token' => $invite['token']],
        );

        $response = $this->controller()->accept(
            $invite['token'],
            $request,
            self::getContainer()->get(Security::class),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Repeat password',
            (string) $response->getContent(),
        );
    }

    public function testARejectedPasswordLeavesTheFormOpen(): void
    {
        $invite = $this->invite($this->company('nexunt'));
        $request = $this->request(
            '/en/company/invite/x',
            'POST',
            ['token' => $invite['token']],
        );
        $request->request->set(
            'company_user_invite_accept',
            [
                '_csrf_token' => 'csrf-token',
                'plainPassword' => [
                    'first' => self::PASSWORD,
                    'second' => 'Something-Else-9',
                ],
            ],
        );

        $response = $this->controller()->accept(
            $invite['token'],
            $request,
            self::getContainer()->get(Security::class),
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertNull(self::getContainer()->get(CompanyUserRepository::class)->loadUserByIdentifier(
            $invite['email'],
        ));
    }

    /**
     * The account is created either way; what differs is whether the portal will let them in, and the page says which.
     */
    public function testAnAccountForACompanyWithoutAContractIsCreatedButNotSignedIn(): void
    {
        $request = $this->request(
            '/en/company/invite/x',
            'POST',
        );
        $request->request->set(
            'company_user_invite_accept',
            [
                '_csrf_token' => 'csrf-token',
                'plainPassword' => [
                    'first' => self::PASSWORD,
                    'second' => self::PASSWORD,
                ],
            ],
        );
        $invite = $this->invite($this->company('halcyon-mobility'));

        $response = $this->controller()->accept(
            $invite['token'],
            $request,
            self::getContainer()->get(Security::class),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company/login',
            self::redirectTarget($response),
        );
        self::assertNotSame(
            [],
            self::flashBag($request)->get('warning'),
        );

        $created = self::getContainer()->get(CompanyUserRepository::class)->loadUserByIdentifier(
            $invite['email'],
        );
        self::assertInstanceOf(
            CompanyUser::class,
            $created,
        );
    }

    public function testAnAcceptedInvitationSignsTheRepresentativeIn(): void
    {
        $request = $this->request(
            '/en/company/invite/x',
            'POST',
        );
        $request->request->set(
            'company_user_invite_accept',
            [
                '_csrf_token' => 'csrf-token',
                'plainPassword' => [
                    'first' => self::PASSWORD,
                    'second' => self::PASSWORD,
                ],
            ],
        );
        $invite = $this->invite($this->company('nexunt'));

        $response = $this->controller()->accept(
            $invite['token'],
            $request,
            self::getContainer()->get(Security::class),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/company',
            self::redirectTarget($response),
        );
        self::assertContains(
            'Welcome. Your account is ready.',
            self::flashBag($request)->get('success'),
        );
    }

    /**
     * @return array{token: string, email: string}
     */
    private function invite(
        Company $company,
        ?DateTimeImmutable $expiresAt = null,
    ): array {
        $split = SplitToken::generate(
            16,
            32,
            CompanyUserInvite::HASH_ALGO,
        );
        $email = 'recruiter-' . $split['selector'] . '@delta-robotics.example.com';

        $invite = new CompanyUserInvite(
            $company,
            $email,
            'Tessa Vermeulen',
            null,
            $split['selector'],
            $split['hashedToken'],
            $expiresAt ?? new DateTimeImmutable('+7 days'),
        );
        $this->entityManager->persist($invite);
        $this->entityManager->flush();

        return [
            'token' => $split['token'],
            'email' => $email,
        ];
    }

    private function company(string $slug): Company
    {
        $company = self::getContainer()->get(CompanyRepository::class)->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Company::class,
            $company,
            'The seed is expected to contain company ' . $slug . '.',
        );

        return $company;
    }

    private function controller(): CompanyInviteController
    {
        return self::getContainer()->get(CompanyInviteController::class);
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function request(
        string $uri,
        string $method,
        array $routeParams = [],
    ): Request {
        $session = $this->session();

        $request = Request::create(
            $uri,
            $method,
        );
        if ('POST' === $method) {
            $request->headers->set(
                'Sec-Fetch-Site',
                'same-origin',
            );
        }

        $request->setLocale('en');
        $request->attributes->set(
            '_route',
            'company_user_invite_accept',
        );
        $request->attributes->set(
            '_route_params',
            $routeParams,
        );
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
}
