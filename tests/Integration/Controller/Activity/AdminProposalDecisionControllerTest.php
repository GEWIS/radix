<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminProposalDecisionController;
use App\Entity\Activity\ActivityDateOption;
use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\BudgetClearance;
use App\Entity\Activity\Enums\DateOptionStatus;
use App\Entity\Activity\Enums\ProposalStatus;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Workflow\Registry;

use function array_filter;
use function array_values;

/**
 * The board's decision queue on the option calendar. The seed has a submitted proposal for each shape the queue
 * handles: a contested day, a board proposal, and one already reserved, cleared or turned down.
 *
 * Actions are invoked directly with the user on the token storage; the session guard force-logs-out a synthetic
 * browser session, so the board gate and CSRF are left to the framework.
 */
final class AdminProposalDecisionControllerTest extends DatabaseTestCase
{
    public function testTheQueueListsTheWaitingProposalsAndTheContestedDays(): void
    {
        // One day a body already gave up on is not a contested day, so it must not reach the grouping.
        $dropped = $this->firstDateOption($this->proposal('Bestuursdiner'));
        $dropped->status = DateOptionStatus::Declined;

        $this->authenticate();
        $this->pushRequest();

        $response = $this->controller()->index();

        self::assertTrue($response->isSuccessful());

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Lasergamecompetitie',
            $content,
        );
        self::assertStringContainsString(
            'Beleidsborrel',
            $content,
        );
        // The seed has three bodies asking for the same day, which is what the contested panel groups.
        self::assertStringContainsString(
            'Bestuursdiner',
            $content,
        );
    }

    public function testReservingADayStartsTheActivityAndSettlesTheOtherDates(): void
    {
        $proposal = $this->proposal('Lasergamecompetitie');
        $option = $this->firstDateOption($proposal);
        $remaining = $proposal->getDateOptions()->count() - 1;

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->approveOption(
            $option,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Scheduled,
            $proposal->status,
        );
        self::assertSame(
            $option,
            $proposal->chosenOption,
        );
        self::assertSame(
            DateOptionStatus::Approved,
            $option->status,
        );
        self::assertNotNull(
            $proposal->activity,
            'Reserving a date is expected to start the activity off as a draft.',
        );
        self::assertSame(
            $this->user(8026)->member,
            $proposal->decidedBy,
        );
        self::assertNotNull($proposal->decidedAt);
        self::assertCount(
            $remaining,
            $this->declinedOptions($proposal),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testADayOfAProposalThatIsNoLongerWaitingIsRefused(): void
    {
        $proposal = $this->proposal('Kascontrole-uitje');
        $option = $this->firstDateOption($proposal);

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->approveOption(
            $option,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Cleared,
            $proposal->status,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Danger->value));
    }

    public function testDecliningAProposalReleasesItsDays(): void
    {
        $proposal = $this->proposal('Beleidsborrel');

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->decline(
            $proposal,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Declined,
            $proposal->status,
        );
        self::assertSame(
            $this->user(8026)->member,
            $proposal->decidedBy,
        );
        self::assertCount(
            $proposal->getDateOptions()->count(),
            $this->declinedOptions($proposal),
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testAProposalThatIsNoLongerWaitingCannotBeDeclined(): void
    {
        $proposal = $this->proposal('Afgewezen plan');
        self::assertSame(
            ProposalStatus::Declined,
            $proposal->status,
        );

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->decline(
            $proposal,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Declined,
            $proposal->status,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Danger->value));
    }

    public function testClearingTheBudgetOfAReservedDayIsRecorded(): void
    {
        $proposal = $this->proposal('Nieuwjaarsborrel');
        self::assertSame(
            ProposalStatus::Scheduled,
            $proposal->status,
        );
        self::assertNull($proposal->budgetClearance);

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->clearance(
            $this->post(['clearance' => BudgetClearance::Approved->value]),
            $proposal,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Cleared,
            $proposal->status,
        );
        self::assertSame(
            BudgetClearance::Approved,
            $proposal->budgetClearance,
        );
        self::assertSame(
            $this->user(8026)->member,
            $proposal->budgetClearedBy,
        );
        self::assertNotNull($proposal->budgetClearedAt);
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testClearingAProposalWithoutAReservedDayIsRefused(): void
    {
        $proposal = $this->proposal('Bestuursdiner');

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->clearance(
            $this->post(['clearance' => BudgetClearance::NotRequired->value]),
            $proposal,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Submitted,
            $proposal->status,
        );
        self::assertNull($proposal->budgetClearance);
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Danger->value));
    }

    public function testPostingNoOutcomeRevokesAClearance(): void
    {
        $proposal = $this->proposal('Kascontrole-uitje');
        self::assertSame(
            BudgetClearance::Approved,
            $proposal->budgetClearance,
        );

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->clearance(
            $this->post([]),
            $proposal,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Scheduled,
            $proposal->status,
        );
        self::assertNull($proposal->budgetClearance);
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Success->value));
    }

    public function testThereIsNothingToRevokeOnAProposalWithoutAClearance(): void
    {
        $proposal = $this->proposal('Bestuursdiner');

        $this->authenticate();
        $session = $this->pushRequest();

        $response = $this->controller()->clearance(
            $this->post([]),
            $proposal,
            $this->user(8026),
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Submitted,
            $proposal->status,
        );
        self::assertNotEmpty($session->getFlashBag()->get(AlertTypes::Danger->value));
    }

    /**
     * The test asks the state machine rather than reading the status, as the controller does: an option on a proposal
     * that already reserved a day cannot be reserved again.
     */
    public function testApprovingIsOnlyOfferedWhileTheStateMachineAllowsIt(): void
    {
        $proposal = $this->proposal('Beleidsborrel');
        $machine = self::getContainer()->get(Registry::class)->get(
            $proposal,
            'activity_proposal',
        );

        self::assertTrue(
            $machine->can(
                $proposal,
                'schedule',
            ),
        );

        $this->authenticate();
        $this->pushRequest();
        $this->controller()->decline(
            $proposal,
            $this->user(8026),
        );

        self::assertFalse(
            $machine->can(
                $proposal,
                'schedule',
            ),
        );
    }

    /**
     * @return list<ActivityDateOption>
     */
    private function declinedOptions(ActivityProposal $proposal): array
    {
        return array_values(array_filter(
            $proposal->getDateOptions()->toArray(),
            static fn (ActivityDateOption $option): bool => DateOptionStatus::Declined === $option->status,
        ));
    }

    private function firstDateOption(ActivityProposal $proposal): ActivityDateOption
    {
        $option = $proposal->getDateOptions()->first();
        self::assertInstanceOf(
            ActivityDateOption::class,
            $option,
        );

        return $option;
    }

    private function proposal(string $name): ActivityProposal
    {
        $proposal = $this->entityManager->getRepository(ActivityProposal::class)->findOneBy([
            'name' => $name,
        ]);
        self::assertInstanceOf(
            ActivityProposal::class,
            $proposal,
            'The seed is expected to contain the proposal "' . $name . '".',
        );

        return $proposal;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function post(array $parameters): Request
    {
        // The action only reads the outcome from the body; the flashes go to the session of the request already on
        // the stack, which is the one the assertions read.
        return Request::create(
            '/en/admin/activities/calendar/decisions/0/clearance',
            'POST',
            $parameters,
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
    }

    private function pushRequest(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        $request->setLocale('en');
        self::getContainer()->get('request_stack')->push($request);

        return $session;
    }

    private function controller(): AdminProposalDecisionController
    {
        return self::getContainer()->get(AdminProposalDecisionController::class);
    }

    private function authenticate(int $lidnr = 8026): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            ['ROLE_BOARD'],
        ));
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }
}
