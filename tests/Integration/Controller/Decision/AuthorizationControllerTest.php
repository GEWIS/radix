<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Decision\AuthorizationController;
use App\Entity\Decision\Authorization;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Form\Decision\AuthorizationType;
use App\Repository\Decision\AuthorizationRepository;
use App\Repository\Decision\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function count;
use function sprintf;

/**
 * A member's proxy vote: the page for the upcoming GMM, granting one, and revoking it again. Every submission
 * redirects, so the tests read what changed from the database and the flash bag.
 */
final class AuthorizationControllerTest extends DatabaseTestCase
{
    public function testTheUpcomingMeetingAndItsFormAreShown(): void
    {
        $this->authenticateMember(8030);
        $request = $this->pushRequest();
        $meeting = $this->upcomingMeeting();

        $response = $this->controller()->index($request);

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Authorizations',
            $content,
        );
        self::assertStringContainsString(
            sprintf(
                'GMM %d',
                $meeting->number,
            ),
            $content,
        );
    }

    /**
     * Asking for a meeting that is not on offer falls back to the soonest one, which is the one the page exists for.
     */
    public function testAnUnknownMeetingFallsBackToTheSoonest(): void
    {
        $this->authenticateMember(8030);
        $request = $this->pushRequest();
        $meeting = $this->upcomingMeeting();

        $response = $this->controller()->index(
            $request,
            99999,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                'GMM %d',
                $meeting->number,
            ),
            (string) $response->getContent(),
        );
    }

    public function testAMeetingCanBeChosenByItsNumber(): void
    {
        $this->authenticateMember(8030);
        $request = $this->pushRequest();

        // The further upcoming GMM: not the one the page would pick on its own.
        $meeting = $this->meetings()[1];

        $response = $this->controller()->index(
            $request,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            sprintf(
                'GMM %d',
                $meeting->number,
            ),
            (string) $response->getContent(),
        );
    }

    public function testAuthorizingAnotherMemberIsRegistered(): void
    {
        $this->authenticateMember(8030);
        $meeting = $this->upcomingMeeting();
        $request = $this->submission(8005);

        $response = $this->controller()->index(
            $request,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'meeting=' . $meeting->number,
            self::redirectTarget($response),
        );
        self::assertNotEmpty(
            self::flashBag($request)->get('success'),
        );
        self::assertNotNull(
            self::getContainer()->get(AuthorizationRepository::class)->findUserAuthorization(
                $meeting->number,
                $this->member(8030),
            ),
        );
    }

    /**
     * An expired member cannot vote, so they cannot vote for somebody else either. The reason is what the member is
     * told, rather than a page that silently did nothing.
     */
    public function testAuthorizingAMemberWhoCannotVoteIsRefused(): void
    {
        $this->authenticateMember(8030);
        $meeting = $this->upcomingMeeting();
        $request = $this->submission(8157);

        $response = $this->controller()->index(
            $request,
            $meeting->number,
        );

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertContains(
            'This member cannot receive authorizations.',
            self::flashBag($request)->get('danger'),
        );
        self::assertNull(
            self::getContainer()->get(AuthorizationRepository::class)->findUserAuthorization(
                $meeting->number,
                $this->member(8030),
            ),
        );
    }

    public function testAnAuthorizationCanBeRevoked(): void
    {
        $this->authenticateMember(8030);
        $meeting = $this->upcomingMeeting();

        $authorization = new Authorization();
        $authorization->authorizer = $this->member(8030);
        $authorization->recipient = $this->member(8005);
        $authorization->meetingNumber = $meeting->number;
        $authorization->createdAt = new DateTimeImmutable();
        $this->entityManager->persist($authorization);
        $this->entityManager->flush();

        $request = $this->revocation($meeting->number);

        $response = $this->controller()->revoke($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertNotNull($authorization->revokedAt);
        self::assertNotEmpty(
            self::flashBag($request)->get('success'),
        );
    }

    /**
     * Revoking something that is not there is not an error: the member lands back on the page unchanged.
     */
    public function testRevokingWithoutAnAuthorizationLeavesNothingToRevoke(): void
    {
        $this->authenticateMember(8030);
        $meeting = $this->upcomingMeeting();

        $request = $this->revocation($meeting->number);

        $response = $this->controller()->revoke($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            [],
            self::flashBag($request)->peek('success'),
        );
    }

    private function controller(): AuthorizationController
    {
        return self::getContainer()->get(AuthorizationController::class);
    }

    /**
     * @return Meeting[]
     */
    private function meetings(): array
    {
        $meetings = self::getContainer()->get(MeetingRepository::class)->findUpcomingALVs();
        self::assertGreaterThanOrEqual(
            2,
            count($meetings),
            'The seed is expected to contain two upcoming GMMs.',
        );

        return $meetings;
    }

    private function upcomingMeeting(): Meeting
    {
        return $this->meetings()[0];
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->getRepository(Member::class)->find($lidnr);
        self::assertInstanceOf(
            Member::class,
            $member,
            sprintf(
                'The seed is expected to contain member %d.',
                $lidnr,
            ),
        );

        return $member;
    }

    /**
     * The authorization form as the page submits it: the recipient and the agreement, with the stateless CSRF token
     * a same-origin fetch sends.
     */
    private function submission(int $recipient): Request
    {
        $request = Request::create(
            '/members/authorizations',
            'POST',
            [
                $this->formName() => [
                    'recipient' => (string) $recipient,
                    'agreement' => '1',
                    '_csrf_token' => 'csrf-token',
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );

        $this->attachSession($request);

        return $request;
    }

    private function revocation(int $meetingNumber): Request
    {
        $request = Request::create(
            '/members/authorizations/revoke',
            'POST',
            ['meeting' => (string) $meetingNumber],
        );
        $this->attachSession($request);

        return $request;
    }

    private function formName(): string
    {
        return self::getContainer()->get('form.factory')->create(AuthorizationType::class)->getName();
    }

    private function pushRequest(): Request
    {
        $request = new Request();
        $this->attachSession($request);

        return $request;
    }

    private function attachSession(Request $request): void
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

    private function authenticateMember(int $lidnr): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
            sprintf(
                'The seed is expected to contain a user for member %d.',
                $lidnr,
            ),
        );

        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $user,
                'main',
                [
                    UserRoles::Member->value,
                    UserRoles::User->value,
                ],
            ),
        );
    }
}
