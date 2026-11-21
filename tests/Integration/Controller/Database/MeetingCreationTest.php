<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\MeetingController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Creating a meeting, and finding one for a decision to refer to. The board meets at a number that has to be free, so
 * the number is the one field a submission can be refused on. Invoked directly; the class and action guards are
 * enforced at the HTTP layer.
 */
final class MeetingCreationTest extends DatabaseTestCase
{
    public function testANewMeetingIsCreatedAndItsPageIsOpened(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'type' => MeetingTypes::BV->value,
            'number' => '9876',
            'date' => '2026-05-01',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MeetingController::class)->create($request);

        self::assertSame(
            Response::HTTP_FOUND,
            $response->getStatusCode(),
        );
        self::assertStringEndsWith(
            '/admin/meetings/BV/9876',
            self::redirectTarget($response),
        );

        $meeting = self::getContainer()->get(MeetingRepository::class)->findMeeting(
            MeetingTypes::BV,
            9876,
        );
        self::assertNotNull(
            $meeting,
            'The submitted meeting is expected to be recorded.',
        );
    }

    public function testAMeetingNumberAlreadyInUseIsRejectedOnTheNumber(): void
    {
        $this->authenticate();
        $request = $this->postRequest([
            '_csrf_token' => 'csrf-token',
            'type' => MeetingTypes::BV->value,
            'number' => '1',
            'date' => '2026-05-01',
        ]);
        $this->pushRequest($request);

        $response = self::getContainer()->get(MeetingController::class)->create($request);

        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $response->getStatusCode(),
        );
        self::assertStringContainsString(
            'This meeting already exists.',
            (string) $response->getContent(),
        );
    }

    public function testTheSearchOffersMeetingsForADecisionToReferTo(): void
    {
        $this->authenticate();

        $response = self::getContainer()->get(MeetingController::class)->search(Request::create(
            '/en/meetings/search',
            'GET',
            ['q' => 'BV'],
        ));

        self::assertInstanceOf(
            JsonResponse::class,
            $response,
        );
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertNotSame(
            '[]',
            (string) $response->getContent(),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): Request
    {
        $request = Request::create(
            '/en/meetings/create',
            'POST',
            ['create_meeting' => $body],
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
            'The seed is expected to contain a user that keeps the register.',
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
