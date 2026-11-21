<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\ProspectiveMemberController;
use App\Entity\Database\CheckoutSession;
use App\Entity\Database\Enums\CheckoutSessionStates;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\Database\PaymentLink;
use App\Entity\Database\ProspectiveMember;
use App\Entity\Database\RenewalLink;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\ProspectiveMemberRepository;
use App\Service\Database\ActionLinkService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function preg_match;
use function sprintf;
use function str_repeat;

/**
 * Everyone who registered but is not a member yet: the public sign-up flow, the graduate renewal link, and the
 * secretary's four actions on an applicant. The flows are driven directly, with the request and session the browser
 * would have.
 */
final class ProspectiveMemberControllerTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $manager,
        );
        $this->ledger = $manager;
    }

    public function testTheUnlocalisedSignUpAddressForwardsToALanguage(): void
    {
        $request = $this->request(
            '/join',
            'GET',
        );
        $request->headers->set(
            'Accept-Language',
            'nl',
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->subscribeUnlocalised($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/join',
            self::redirectTarget($response),
        );
    }

    public function testTheSignUpFormOpens(): void
    {
        $request = $this->request(
            '/en/join',
            'GET',
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->subscribe($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAnEmptySignUpSubmissionIsRejected(): void
    {
        $request = $this->request(
            '/en/join',
            'POST',
        );
        $request->request->set(
            'registration_flow',
            ['_csrf_token' => 'csrf-token'],
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->subscribe($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
    }

    public function testAnUnknownRenewalTokenSaysTheLinkNoLongerWorks(): void
    {
        $this->request(
            '/en/renew',
            'GET',
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->renewClaim(
            str_repeat(
                'a',
                32,
            ) . '.' . str_repeat(
                'b',
                64,
            ),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'link no longer works',
            (string) $response->getContent(),
        );
    }

    public function testARenewalLinkIsExchangedForAOneUseHash(): void
    {
        $link = $this->renewalLink();

        $response = self::getContainer()->get(ProspectiveMemberController::class)->renewClaim(
            (string) $link->plainToken,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            '/renew?th=',
            self::redirectTarget($response),
        );
    }

    public function testTheHashBindsTheRenewalToTheSession(): void
    {
        $link = $this->renewalLink();
        self::getContainer()->get(ActionLinkService::class)->claim($link);

        $request = $this->request(
            '/en/join/renew',
            'GET',
            ['th' => $link->tempHash],
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->renew($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            $link->id,
            $request->getSession()->get('_renewal_link_id'),
        );
    }

    public function testRenewalWithoutAHashOrASessionIsUnavailable(): void
    {
        $request = $this->request(
            '/en/join/renew',
            'GET',
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->renew($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'link no longer works',
            (string) $response->getContent(),
        );
    }

    public function testARenewalLinkThatWasUsedMeanwhileIsUnavailable(): void
    {
        $link = $this->renewalLink();
        $request = $this->request(
            '/en/join/renew',
            'GET',
        );
        $request->getSession()->set(
            '_renewal_link_id',
            $link->id,
        );

        $link->used = true;
        $this->ledger->flush();

        $response = self::getContainer()->get(ProspectiveMemberController::class)->renew($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'link no longer works',
            (string) $response->getContent(),
        );
    }

    public function testTheRenewalFormShowsTheDetailsAndUntilWhenTheMembershipWouldRun(): void
    {
        $link = $this->renewalLink();
        $request = $this->request(
            '/en/join/renew',
            'GET',
        );
        $request->getSession()->set(
            '_renewal_link_id',
            $link->id,
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->renew($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Renew until',
            $content,
        );
        // The date follows from the link rather than from anything the member submits.
        self::assertStringContainsString(
            $link->newExpiration->format('Y-m-d'),
            $content,
        );
        self::assertStringContainsString(
            (string) $link->member->email,
            $content,
        );
    }

    public function testSubmittingTheRenewalExtendsTheMembershipAndUsesUpTheLink(): void
    {
        $link = $this->renewalLink();
        $request = $this->request(
            '/en/join/renew',
            'POST',
        );
        $request->getSession()->set(
            '_renewal_link_id',
            $link->id,
        );
        $request->request->set(
            'member_renewal',
            [
                '_csrf_token' => 'csrf-token',
                'email' => (string) $link->member->email,
                'supremum' => '1',
                'privacy' => '1',
                'agreed' => '1',
                'submit' => '',
            ],
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->renew($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertTrue($link->used);
        self::assertNull($request->getSession()->get('_renewal_link_id'));
    }

    public function testTheApplicantOverviewRenders(): void
    {
        $this->authenticate();
        $this->request(
            '/en/admin/members/prospective',
            'GET',
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testTheApplicantPageRenders(): void
    {
        $this->authenticate();
        $this->request(
            '/en/admin/members/prospective/1',
            'GET',
        );
        $prospective = $this->prospective();

        $response = self::getContainer()->get(ProspectiveMemberController::class)->show($prospective->lidnr);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            $prospective->firstName,
            (string) $response->getContent(),
        );
    }

    public function testAnApplicantThatIsNotOnFileIsNotFound(): void
    {
        $this->authenticate();
        $this->request(
            '/en/admin/members/prospective/999999',
            'GET',
        );

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(ProspectiveMemberController::class)->show(999999);
    }

    public function testApprovingAnApplicantCreatesTheMember(): void
    {
        $this->authenticate();
        $prospective = $this->prospective();
        $request = $this->request(
            sprintf(
                '/en/admin/members/prospective/%d/finalize',
                $prospective->lidnr,
            ),
            'POST',
        );
        $request->request->set(
            'member_approve',
            [
                '_csrf_token' => 'csrf-token',
                'type' => MembershipTypes::Ordinary->value,
            ],
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->finalize(
            $request,
            $prospective->lidnr,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertMatchesRegularExpression(
            '#/admin/members/(\d+)$#',
            self::redirectTarget($response),
        );
        preg_match(
            '#/admin/members/(\d+)$#',
            self::redirectTarget($response),
            $matches,
        );
        self::assertArrayHasKey(
            1,
            $matches,
        );
        self::assertNotNull($this->ledger->getRepository(Member::class)->find((int) $matches[1]));
    }

    public function testApprovingWithoutAMembershipTypeIsRefused(): void
    {
        $this->authenticate();
        $prospective = $this->prospective();
        $request = $this->request(
            sprintf(
                '/en/admin/members/prospective/%d/finalize',
                $prospective->lidnr,
            ),
            'POST',
        );
        $request->request->set(
            'member_approve',
            ['_csrf_token' => 'csrf-token'],
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->finalize(
            $request,
            $prospective->lidnr,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'This prospective member cannot be approved.',
            self::flashBag($request)->get('danger'),
        );
    }

    public function testResendingWithoutAPaymentLinkSaysThereIsNothingToPay(): void
    {
        $this->authenticate();
        $prospective = $this->prospective();
        $request = $this->request(
            sprintf(
                '/en/admin/members/prospective/%d/resend',
                $prospective->lidnr,
            ),
            'POST',
        );

        $response = self::getContainer()->get(ProspectiveMemberController::class)->resend($prospective->lidnr);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        $flashes = self::flashBag($request)->get('danger');
        self::assertCount(
            1,
            $flashes,
        );
        self::assertStringContainsString(
            'no payment left to make',
            $flashes[0]->getMessage(),
        );
    }

    public function testResendingAPaymentLinkSendsItAgain(): void
    {
        $this->authenticate();
        $prospective = $this->prospective();
        $paymentLink = new PaymentLink();
        $paymentLink->prospectiveMember = $prospective;
        $this->ledger->persist($paymentLink);
        $prospective->setPaymentLink($paymentLink);
        $this->ledger->flush();

        $request = $this->request(
            sprintf(
                '/en/admin/members/prospective/%d/resend',
                $prospective->lidnr,
            ),
            'POST',
        );
        $response = self::getContainer()->get(ProspectiveMemberController::class)->resend($prospective->lidnr);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        $flashes = self::flashBag($request)->get('success');
        self::assertCount(
            1,
            $flashes,
        );
        self::assertStringContainsString(
            'payment link has been sent again',
            $flashes[0]->getMessage(),
        );
    }

    public function testRemovingAnApplicantThatWasNotPaidDeletesThem(): void
    {
        $this->authenticate();
        $prospective = $this->prospective();

        $checkout = $prospective->getCheckoutSessions()->last();
        self::assertInstanceOf(
            CheckoutSession::class,
            $checkout,
        );
        $checkout->state = CheckoutSessionStates::Expired;
        $checkout->expiration = new DateTimeImmutable('-1 day');
        $prospective->paid = 0;
        $this->ledger->flush();

        $id = $prospective->lidnr;
        $request = $this->request(
            sprintf(
                '/en/admin/members/prospective/%d/delete',
                $id,
            ),
            'POST',
        );
        $response = self::getContainer()->get(ProspectiveMemberController::class)->delete($id);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/members/prospective',
            self::redirectTarget($response),
        );
        self::assertNull(self::getContainer()->get(ProspectiveMemberRepository::class)->find($id));
    }

    private function renewalLink(): RenewalLink
    {
        $member = $this->ledger->getRepository(Member::class)->find(8155);
        self::assertInstanceOf(
            Member::class,
            $member,
            'The seed is expected to contain a graduate whose membership can be renewed.',
        );

        $member->setEmail('renewal-target@example.com');
        $this->ledger->persist($member);

        $link = new RenewalLink(
            $member,
            new DateTimeImmutable('+2 years'),
        );
        $this->ledger->persist($link);
        $this->ledger->flush();

        return $link;
    }

    private function prospective(): ProspectiveMember
    {
        $prospective = self::getContainer()->get(ProspectiveMemberRepository::class)->findOneBy([]);
        self::assertInstanceOf(
            ProspectiveMember::class,
            $prospective,
            'The seed is expected to contain a prospective member.',
        );

        return $prospective;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(
        string $uri,
        string $method,
        array $query = [],
    ): Request {
        $session = $this->session();

        $request = Request::create(
            $uri,
            $method,
            $query,
        );
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
        $user = $this->entityManager->getRepository(User::class)->find(8002);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [
                    UserRoles::Board->value,
                    UserRoles::DatabaseAdmin->value,
                ],
            ),
        );
    }
}
