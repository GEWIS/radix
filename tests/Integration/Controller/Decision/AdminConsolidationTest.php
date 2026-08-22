<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Decision;

use App\Controller\Application\AdminController as DashboardController;
use App\Controller\Decision\AdminBodyController;
use App\Controller\Decision\AdminMeetingController;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\Organ;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function count;

/**
 * The meeting and body pages after the register and the administration were folded into one section.
 *
 * Both used to exist twice: the register described a meeting by its decisions behind /database while the board
 * administered the same meeting's documents behind /admin, and a body's composition and its page were likewise two
 * pages about one thing. They are one page each now, and what a reader is shown of them follows their roles rather
 * than which of the two addresses they arrived at.
 *
 * Invoked directly, which is this codebase's pattern for controller tests; the class-level guards are enforced at the
 * HTTP layer, so what these exercise is the action body and its template.
 */
final class AdminConsolidationTest extends DatabaseTestCase
{
    /**
     * A member who administers the register but holds no board seat.
     */
    private const int REGISTER_ADMIN = 8002;

    /**
     * A member of the board serving now, who administers no part of the register.
     */
    private const int BOARD_MEMBER = 8025;

    public function testAMeetingOffersItsDocumentsToTheSameHandsAsItsDecisions(): void
    {
        // A serving secretary holds both: the register's rights are granted for as long as they are an installed,
        // unrelieved board member, so the two never come apart in practice.
        $this->authenticate(
            self::REGISTER_ADMIN,
            [
                UserRoles::Board->value,
                UserRoles::DatabaseAdmin->value,
            ],
        );
        $this->pushRequest();
        // The documents panel is behind sudo, as it has always been.
        self::getContainer()->get(SudoMode::class)->grant();

        $content = $this->render(
            $this->meetings()->view(
                'gmm',
                $this->aMeetingNumber(),
            ),
        );

        self::assertStringContainsString(
            'Add New Decision',
            $content,
        );
        self::assertStringContainsString(
            'Minutes',
            $content,
            'What is decided and what is filed alongside it are one record, kept by one pair of hands.',
        );
    }

    public function testABoardMemberWhoIsNotTheSecretaryIsOfferedNoWayToChangeAMeeting(): void
    {
        $this->authenticate(
            self::BOARD_MEMBER,
            [UserRoles::Board->value],
        );
        $this->pushRequest();
        self::getContainer()->get(SudoMode::class)->grant();

        $content = $this->render(
            $this->meetings()->view(
                'gmm',
                $this->aMeetingNumber(),
            ),
        );

        // The board reads a meeting; keeping its record is the secretary's job.
        self::assertStringContainsString(
            'Decision',
            $content,
        );
        self::assertStringNotContainsString(
            'Add New Decision',
            $content,
        );
        self::assertStringNotContainsString(
            'Delete Decision',
            $content,
        );
    }

    public function testTheDashboardCarriesTheRegisterForWhoeverAdministersIt(): void
    {
        $this->authenticate(
            self::REGISTER_ADMIN,
            [UserRoles::DatabaseAdmin->value],
        );
        $this->pushRequest();

        $content = $this->render($this->dashboard()->index());

        self::assertStringContainsString(
            'Membership types',
            $content,
            'The register had a dashboard of its own; what it said is a section of this one now.',
        );
    }

    public function testTheDashboardSaysNothingOfTheRegisterToAnyoneElse(): void
    {
        $this->authenticate(
            self::BOARD_MEMBER,
            [UserRoles::Board->value],
        );
        $this->pushRequest();

        $content = $this->render($this->dashboard()->index());

        self::assertStringNotContainsString(
            'Membership types',
            $content,
            'The figures are several queries, and are not assembled for a reader who is not shown them.',
        );
    }

    public function testTheBodyOverviewListsEveryBodyForWhoeverAdministersTheRegister(): void
    {
        $this->authenticate(
            self::REGISTER_ADMIN,
            [UserRoles::DatabaseAdmin->value],
        );
        $this->pushRequest();

        $user = $this->user(self::REGISTER_ADMIN);
        $content = $this->render($this->bodies()->index($user));

        $abrogated = $this->entityManager->getRepository(Organ::class)->findAbrogated();
        self::assertNotEmpty(
            $abrogated,
            'The seed is expected to contain a body that has been abrogated.',
        );

        // The list the board is shown is the bodies whose page they may write, which is the active ones. A register
        // administrator reads the same page as the list of bodies there have ever been.
        self::assertStringContainsString(
            $abrogated[0]->getAbbr(),
            $content,
            'A body that has been abrogated is still a body the register knows about.',
        );
    }

    /**
     * A general members' meeting the seed's calendar holds.
     */
    private function aMeetingNumber(): int
    {
        $meetings = $this->entityManager
            ->getRepository(Meeting::class)
            ->findBy(['type' => MeetingTypes::ALV]);

        self::assertGreaterThan(
            0,
            count($meetings),
            'The seed is expected to contain a general members\' meeting.',
        );

        return $meetings[0]->getNumber();
    }

    private function render(Response $response): string
    {
        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        return (string) $response->getContent();
    }

    private function dashboard(): DashboardController
    {
        return self::getContainer()->get(DashboardController::class);
    }

    private function meetings(): AdminMeetingController
    {
        return self::getContainer()->get(AdminMeetingController::class);
    }

    private function bodies(): AdminBodyController
    {
        return self::getContainer()->get(AdminBodyController::class);
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
            'The seed is expected to contain a user for member ' . $lidnr . '.',
        );

        return $user;
    }

    /**
     * @param string[] $roles
     */
    private function authenticate(
        int $lidnr,
        array $roles,
    ): void {
        self::getContainer()->get('security.token_storage')->setToken(
            new UsernamePasswordToken(
                $this->user($lidnr),
                'main',
                [
                    UserRoles::Member->value,
                    ...$roles,
                ],
            ),
        );
    }

    private function pushRequest(): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        // Sudo mode reads the session off a request that arrived with one, so the cookie has to be there: without it
        // `hasPreviousSession()` is false and nothing that was granted is ever found again.
        $session->start();
        $request->cookies->set(
            $session->getName(),
            $session->getId(),
        );
        self::getContainer()->get('request_stack')->push($request);
    }
}
