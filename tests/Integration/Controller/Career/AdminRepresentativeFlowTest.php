<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Career;

use App\Controller\Career\AdminRepresentativeController;
use App\Entity\Career\Company;
use App\Entity\User\CompanyUser;
use App\Entity\User\CompanyUserInvite;
use App\Entity\User\User;
use App\Repository\Career\CompanyRepository;
use App\Repository\User\CompanyUserInviteRepository;
use App\Repository\User\CompanyUserRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Who may act for a company, from the board's side: inviting somebody, reissuing or withdrawing an invitation, and the
 * routes that must not reach another company's people.
 */
final class AdminRepresentativeFlowTest extends DatabaseTestCase
{
    private SessionInterface $session;

    public function testInvitingSomebodySendsTheInvitation(): void
    {
        $this->signIn();
        $this->open();

        $response = $this->controller()->index(
            $this->companyId(),
            $this->post([
                'name' => 'New Recruiter',
                'email' => 'fresh@nexunt.example.com',
            ]),
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/admin/career/companies/' . $this->companyId() . '/representatives',
            $response->headers->get('Location') ?? '',
        );

        $invite = $this->invites()->findByEmail('fresh@nexunt.example.com');
        self::assertInstanceOf(
            CompanyUserInvite::class,
            $invite,
        );
        self::assertSame(
            'New Recruiter',
            $invite->name,
        );
    }

    public function testInvitingAnAddressThatAlreadySaysWhoTheyAreIsRefused(): void
    {
        $this->signIn();
        $this->open();

        $response = $this->controller()->index(
            $this->companyId(),
            $this->post([
                'name' => 'Ilse Again',
                'email' => 'recruitment@nexunt.example.com',
            ]),
            $this->user(),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'That email address already belongs to a representative.',
            (string) $response->getContent(),
        );
    }

    public function testAnInvitationCanBeSentAgain(): void
    {
        $this->signIn();
        $this->open();
        $invite = $this->inviteOfDelta();
        $selector = $invite->selector;

        $response = $this->controller()->resendInvite(
            (int) $invite->company->id,
            (int) $invite->id,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNotSame(
            $selector,
            $invite->selector,
        );
        self::assertNotEmpty(self::flashBag($this->session)->peek('success'));
    }

    public function testAnInvitationCanBeWithdrawn(): void
    {
        $this->signIn();
        $this->open();
        $invite = $this->inviteOfDelta();
        $inviteId = (int) $invite->id;

        $response = $this->controller()->revokeInvite(
            (int) $invite->company->id,
            $inviteId,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNull($this->invites()->find($inviteId));
        self::assertNotEmpty(self::flashBag($this->session)->peek('success'));
    }

    public function testAnotherCompanysInvitationIsNotReachable(): void
    {
        $this->signIn();
        $this->open();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->revokeInvite(
            $this->companyId(),
            (int) $this->inviteOfDelta()->id,
        );
    }

    public function testAnUnknownInvitationIsNotFound(): void
    {
        $this->signIn();
        $this->open();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->resendInvite(
            $this->companyId(),
            999999,
        );
    }

    public function testAnUnknownCompanyIsNotFound(): void
    {
        $this->signIn();
        $this->open();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->index(
            999999,
            new Request(),
            $this->user(),
        );
    }

    public function testEverySessionOfARepresentativeCanBeEnded(): void
    {
        $this->signIn();
        $this->open();
        $representative = $this->representative('talent@nexunt.example.com');

        $response = $this->controller()->terminateSessions(
            $this->companyId(),
            (int) $representative->id,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/representatives',
            $response->headers->get('Location') ?? '',
        );
        $flashes = self::flashBag($this->session)->peek('success');
        self::assertNotEmpty($flashes);
        self::assertStringContainsString(
            'session(s) terminated',
            $flashes[0],
        );
    }

    public function testARepresentativeOfAnotherCompanyCannotBeEndedThroughThisOne(): void
    {
        $this->signIn();
        $this->open();

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->terminateSessions(
            $this->companyId(),
            (int) $this->representative('recruitment@orbit-analytics.example.com')->id,
        );
    }

    private function inviteOfDelta(): CompanyUserInvite
    {
        $invite = $this->invites()->findByEmail('new-recruiter@delta-robotics.example.com');
        self::assertInstanceOf(
            CompanyUserInvite::class,
            $invite,
        );

        return $invite;
    }

    private function invites(): CompanyUserInviteRepository
    {
        return self::getContainer()->get(CompanyUserInviteRepository::class);
    }

    private function representative(string $email): CompanyUser
    {
        $companyUser = self::getContainer()->get(CompanyUserRepository::class)
            ->loadUserByIdentifier($email);
        self::assertInstanceOf(
            CompanyUser::class,
            $companyUser,
        );

        return $companyUser;
    }

    private function controller(): AdminRepresentativeController
    {
        return self::getContainer()->get(AdminRepresentativeController::class);
    }

    private function companyId(): int
    {
        return (int) $this->company()->id;
    }

    private function company(string $slug = 'nexunt'): Company
    {
        $company = self::getContainer()->get(CompanyRepository::class)
            ->findOneBy(['slugName' => $slug]);
        self::assertInstanceOf(
            Company::class,
            $company,
        );

        return $company;
    }

    private function signIn(int $lidnr = 8025): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            ['ROLE_COMPANY_ADMIN'],
        ));
    }

    private function user(int $lidnr = 8025): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    private function open(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $this->session = $session;

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(array $fields): Request
    {
        $fields['_csrf_token'] = self::getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('submit')
            ->getValue();

        $request = new Request(
            request: ['company_representative_invite' => $fields],
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
        );
        $request->setMethod(Request::METHOD_POST);
        $request->setSession($this->session);
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }
}
