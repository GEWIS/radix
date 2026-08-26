<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Database;

use App\Controller\Database\DecisionController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Database\MeetingRepository;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The page that offers every kind of decision for one place in a meeting. It renders a dozen forms at once, three of
 * which hand their collection prototype to a Stimulus controller before the form theme gets to it, so rendering it at
 * all is the thing worth asserting.
 */
final class DecisionControllerTest extends DatabaseTestCase
{
    public function testEveryDecisionFormIsOffered(): void
    {
        $this->authenticateSecretary();
        $this->pushRequest();
        $meeting = $this->latestVirtualMeeting();

        $response = self::getContainer()->get(DecisionController::class)->create(
            MeetingTypes::VIRT,
            $meeting,
            99,
            99,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $content = (string) $response->getContent();

        foreach (
            [
                'tab-organ-install',
                'tab-board-install',
                'tab-member-warning',
                'tab-member-suspension',
                'tab-other',
            ] as $kind
        ) {
            self::assertStringContainsString(
                $kind,
                $content,
            );
        }
    }

    /**
     * The page extends the register's layout, which asks the current request for the language it renders in and
     * the session for the language switch.
     */
    private function pushRequest(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setLocale('en');
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }

    private function latestVirtualMeeting(): int
    {
        $repository = self::getContainer()->get(MeetingRepository::class);
        $meeting = $repository->findMeeting(
            MeetingTypes::VIRT,
            2,
        );

        self::assertNotNull(
            $meeting,
            'The seed is expected to contain a second virtual meeting.',
        );

        return $meeting->getNumber();
    }

    private function authenticateSecretary(): void
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
