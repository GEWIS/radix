<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\MembershipController;
use App\Entity\Database\Enums\MembershipTypes;
use App\Entity\Database\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\MemberRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function strtolower;

/**
 * A member's membership type and its expiry. The change date is clamped to the period that runs latest, so a valid
 * submission always applies; what is worth asserting is that it does, and that the page is returned to the member.
 * Invoked directly, mirroring the rest of the suite.
 */
final class MembershipControllerTest extends DatabaseTestCase
{
    private const int MEMBER = 8030;

    public function testChangingTheMembershipTypeStoresIt(): void
    {
        $this->authenticate();
        $member = $this->member();
        $before = $member->getLastMembership();
        self::assertNotNull($before);

        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'type' => MembershipTypes::External->value,
            'changeDate' => '2026-02-01',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MembershipController::class)->membership(
            $request,
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/members/' . self::MEMBER,
            self::redirectTarget($response),
        );
        self::assertSame(
            MembershipTypes::External,
            $member->getLastMembership()?->type,
        );
    }

    public function testAnUnknownMembershipTypeLeavesThePageOpen(): void
    {
        $this->authenticate();

        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'type' => 'not-a-type',
            'changeDate' => '2026-02-01',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MembershipController::class)->membership(
            $request,
            self::MEMBER,
        );

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
    }

    public function testExtendingTheMembershipAddsAPeriod(): void
    {
        $this->authenticate();
        $member = $this->member();
        $memberships = $member->getMemberships()->count();

        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MembershipController::class)->expiration(self::MEMBER);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertCount(
            $memberships + 1,
            $member->getMemberships(),
        );
    }

    public function testTheDeletedMemberIsReplacedByTheNotice(): void
    {
        $this->authenticate();
        $this->pushRequest(new Request());

        $response = self::getContainer()->get(MembershipController::class)->membership(
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

        self::getContainer()->get(MembershipController::class)->membership(
            new Request(),
            999999,
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

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): Request
    {
        $request = Request::create(
            '/en/admin/members/' . self::MEMBER . '/edit/membership',
            'POST',
            ['membership_type' => $body],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        return $request;
    }

    private function pushRequest(Request $request): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
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
