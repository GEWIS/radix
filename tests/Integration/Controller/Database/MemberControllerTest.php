<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\MemberController;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function strtolower;

/**
 * The register's member pages: the searches behind the overview, the record itself, and the actions that are about one
 * member without being an entity of their own. The deleted member replaces every page about them with a notice.
 */
final class MemberControllerTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;

    public function testTheOverviewIsAStaticPageThatTheBrowserFills(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->index();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testTheSearchReturnsJson(): void
    {
        $this->authenticate();

        $response = self::getContainer()->get(MemberController::class)->searchFiltered(Request::create(
            '/en/admin/members/searchFiltered',
            'GET',
            ['q' => 'Leeuwen'],
        ));

        self::assertInstanceOf(
            JsonResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'Leeuwen',
            (string) $response->getContent(),
        );
    }

    public function testTheAttentionOverviewRenders(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->attention();

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testTheBulkRenewalPreviewsTheSubmittedNumbers(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->bulkRenewal(Request::create(
            '/en/admin/members/bulk-renewal',
            'GET',
            [
                'memberIds' => '8030',
                'membershipType' => MembershipTypes::Ordinary->value,
            ],
        ));

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Leeuwen',
            (string) $response->getContent(),
        );
    }

    public function testTheBulkRenewalRejectsNumbersThatAreNotNumeric(): void
    {
        $this->authenticate();
        $request = Request::create(
            '/en/admin/members/bulk-renewal',
            'POST',
            [
                'bulk_member_renewal' => [
                    '_csrf_token' => 'csrf-token',
                    'memberIds' => 'not a number',
                    'membershipType' => MembershipTypes::Ordinary->value,
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $this->pushRequest($request);

        $response = self::getContainer()->get(MemberController::class)->bulkRenewal($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
    }

    public function testTheMemberPageRendersTheRecord(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->show(
            new Request(),
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Leeuwen',
            (string) $response->getContent(),
        );
    }

    public function testLeavingANoteReturnsToTheMember(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());

        $request = Request::create(
            '/en/admin/members/' . self::MEMBER,
            'POST',
            [
                'audit_note' => [
                    '_csrf_token' => 'csrf-token',
                    'note' => 'Called about the renewal.',
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(MemberController::class)->show(
            $request,
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNotSame(
            [],
            self::flashBag($session)->get('success'),
        );
    }

    public function testADeletedMemberIsReplacedByTheNotice(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->show(
            new Request(),
            8205,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'deleted',
            strtolower((string) $response->getContent()),
        );
    }

    public function testAMemberThatDoesNotExistIsNotFound(): void
    {
        $this->authenticate();

        $this->expectException(NotFoundHttpException::class);

        self::getContainer()->get(MemberController::class)->show(
            new Request(),
            999999,
        );
    }

    public function testTheEditFormRenders(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->edit(
            new Request(),
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'Change Data',
            (string) $response->getContent(),
        );
    }

    public function testEditingAMemberStoresTheNewName(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());

        $request = Request::create(
            '/en/admin/members/' . self::MEMBER . '/edit',
            'POST',
            [
                'member_edit' => [
                    '_csrf_token' => 'csrf-token',
                    'lastName' => 'Nieuwenaam',
                    'middleName' => '',
                    'initials' => 'T.',
                    'firstName' => 'Leeuwen',
                    'studentNumber' => '1000020',
                    'email' => 'timon@example.com',
                    'birth' => '2000-01-01',
                    'study' => 'Bachelor Applied Mathematics',
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(MemberController::class)->edit(
            $request,
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'Nieuwenaam',
            $this->member()->lastName,
        );
    }

    public function testEditingTheDeletedMemberIsReplacedByTheNotice(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->edit(
            new Request(),
            8205,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'deleted',
            strtolower((string) $response->getContent()),
        );
    }

    public function testDeletingAMemberReturnsToTheOverview(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->delete(self::MEMBER);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/members',
            self::redirectTarget($response),
        );
        self::assertTrue($this->member()->deleted);
    }

    public function testTheSupremumAnswerIsStored(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->supremum(
            self::MEMBER,
            'optin',
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'optin',
            $this->member()->supremum,
        );
    }

    public function testTheMailingListSubscriptionsAreOffered(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MemberController::class)->lists(
            new Request(),
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'activities',
            (string) $response->getContent(),
        );
    }

    public function testSubscribingToAListReturnsToTheMember(): void
    {
        $this->authenticate();
        $session = $this->pushRequest(new Request());

        $request = Request::create(
            '/en/admin/members/' . self::MEMBER . '/edit/lists',
            'POST',
            [
                'member_lists' => [
                    '_csrf_token' => 'csrf-token',
                    'lists' => ['activities'],
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        $response = self::getContainer()->get(MemberController::class)->lists(
            $request,
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
    }

    private function member(): Member
    {
        $member = self::getContainer()->get(MemberRepository::class)->find(self::MEMBER);
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
    }

    private function pushRequest(Request $request): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

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
