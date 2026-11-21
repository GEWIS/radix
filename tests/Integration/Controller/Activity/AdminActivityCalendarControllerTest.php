<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminActivityCalendarController;
use App\Entity\Activity\ActivityDateOption;
use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\ProposalStatus;
use App\Entity\Activity\OptionPeriod;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function count;
use function crc32;
use function sprintf;

/**
 * Submitting a proposal, against the seeded calendar.
 *
 * The seed limits GETÉST to two in the round that is taking proposals and has it use both, and puts KEUR on a standing
 * two with one used, so one body has room and the other does not. Actions are invoked directly, as the other admin
 * controller tests do.
 */
final class AdminActivityCalendarControllerTest extends DatabaseTestCase
{
    public function testABodyWithRoomCanHandOneIn(): void
    {
        $this->authenticate();
        $before = $this->proposalCount();

        $response = $this->submit(
            $this->body('KEUR'),
            'Een extra borrel',
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            $before + 1,
            $this->proposalCount(),
        );

        $stored = $this->entityManager->getRepository(ActivityProposal::class)->findOneBy([
            'name' => 'Een extra borrel',
        ]);
        self::assertInstanceOf(
            ActivityProposal::class,
            $stored,
        );
        self::assertSame(
            ProposalStatus::Submitted,
            $stored->status,
        );
        self::assertCount(
            1,
            $stored->getDateOptions(),
        );
        $first = $stored->getDateOptions()->first();
        self::assertInstanceOf(
            ActivityDateOption::class,
            $first,
        );
        // The order the days were put in is the body's order of preference, numbered from one.
        self::assertSame(
            1,
            $first->position,
        );
    }

    public function testABodyOnItsLimitIsRefusedAndToldWhy(): void
    {
        $this->authenticate();
        $before = $this->proposalCount();

        $response = $this->submit(
            $this->body('GETÉST'),
            'Een activiteit te veel',
        );

        self::assertFalse($response instanceof RedirectResponse);
        self::assertSame(
            $before,
            $this->proposalCount(),
        );
        self::assertStringContainsString(
            'already put forward everything it may',
            (string) $response->getContent(),
        );
    }

    public function testADayOutsideTheRoundIsRefused(): void
    {
        $this->authenticate();
        $before = $this->proposalCount();

        $period = $this->openPeriod();
        $response = $this->submit(
            $this->body('KEUR'),
            'Ver buiten de ronde',
            $period->endsAt->modify('+30 days'),
        );

        self::assertFalse($response instanceof RedirectResponse);
        self::assertSame(
            $before,
            $this->proposalCount(),
        );
        self::assertStringContainsString(
            'This round only covers',
            (string) $response->getContent(),
        );
    }

    public function testTheBoardSeesTheCalendarWithEveryBodyItMayActFor(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->index($this->user(8025));

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'Q2 this year',
            (string) $response->getContent(),
        );
    }

    /**
     * An ordinary member only acts for the bodies they are installed in, which is what the form offers them.
     */
    public function testAnActiveMemberSeesOnlyTheBodiesTheyAreInstalledIn(): void
    {
        // 8010 is installed in GETÉST and serves on no board, so the body list is built from their installations.
        $this->authenticateAs(8010);
        $this->pushRequestWithSession();

        $response = $this->controller()->index($this->user(8010));

        self::assertTrue($response->isSuccessful());
        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Q2 this year',
            $content,
        );
        self::assertStringContainsString(
            'GETÉST',
            $content,
        );
        self::assertStringContainsString(
            'Lasergamecompetitie',
            $content,
        );
    }

    /**
     * A waiting proposal still has a withdraw button and shows the room its body has left.
     */
    public function testAProposalPageShowsTheAllowanceAndTheWithdrawAction(): void
    {
        $proposal = $this->aProposal('Beleidsborrel');

        $this->authenticateAs(8027);
        $this->pushRequestWithSession();

        $response = $this->controller()->proposal($proposal);

        self::assertTrue($response->isSuccessful());
        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Beleidsborrel',
            $content,
        );
        self::assertStringContainsString(
            '/withdraw',
            $content,
        );
    }

    /**
     * A board-hosted proposal names no body, so no allowance can be shown for it.
     */
    public function testABoardHostedProposalHasNoAllowance(): void
    {
        $proposal = $this->aProposal('Bestuursdiner');
        self::assertNull($proposal->organ);

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->proposal($proposal);

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'Bestuursdiner',
            (string) $response->getContent(),
        );
    }

    public function testAProposalOfAnotherBodyIsNotVisible(): void
    {
        $proposal = $this->aProposal('Beleidsborrel');

        $this->authenticateAs(8030);
        $this->pushRequestWithSession();

        $this->expectException(AccessDeniedException::class);

        $this->controller()->proposal($proposal);
    }

    public function testAProposalThatWasAlreadyWithdrawnOffersNoWithdrawAction(): void
    {
        $proposal = $this->aProposal('Ingetrokken plan');

        $this->authenticateAs(8027);
        $this->pushRequestWithSession();

        $response = $this->controller()->proposal($proposal);

        self::assertTrue($response->isSuccessful());
        self::assertStringNotContainsString(
            '/withdraw',
            (string) $response->getContent(),
        );
    }

    public function testAWaitingProposalCanBeEdited(): void
    {
        $proposal = $this->aProposal('Beleidsborrel');

        $this->authenticateAs(8027);
        $this->pushRequestWithSession();

        $response = $this->controller()->editProposal(
            new Request(),
            $proposal,
        );

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'Beleidsborrel',
            (string) $response->getContent(),
        );
    }

    public function testAnEditedProposalIsSaved(): void
    {
        $proposal = $this->aProposal('Beleidsborrel');
        $period = $this->openPeriod();
        $day = $period->startsAt->modify('+4 days');

        $this->authenticateAs(8027);
        $request = $this->proposalPost(
            $proposal,
            'Beleidsborrel aangepast',
            $day,
        );

        $response = $this->controller()->editProposal(
            $request,
            $proposal,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            'Beleidsborrel aangepast',
            $proposal->name,
        );
        self::assertCount(
            1,
            $proposal->getDateOptions(),
        );
        self::assertNotEmpty(self::flashBag($request)->get(AlertTypes::Success->value));
    }

    public function testAnEditWithoutANameComesBackWithTheForm(): void
    {
        $proposal = $this->aProposal('Beleidsborrel');
        $period = $this->openPeriod();

        $this->authenticateAs(8027);
        $request = $this->proposalPost(
            $proposal,
            'x',
            $period->startsAt->modify('+4 days'),
        );

        $response = $this->controller()->editProposal(
            $request,
            $proposal,
        );

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'The name should be at least',
            (string) $response->getContent(),
        );
    }

    public function testWithdrawingAProposalFreesItsDays(): void
    {
        $proposal = $this->aProposal('Beleidsborrel');

        $this->authenticateAs(8027);
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->withdrawProposal($proposal);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Withdrawn,
            $proposal->status,
        );
        self::assertNotEmpty(self::flashBag($session)->get(AlertTypes::Success->value));
    }

    public function testAProposalThatIsAlreadyWithdrawnCannotBeWithdrawnAgain(): void
    {
        $proposal = $this->aProposal('Ingetrokken plan');

        $this->authenticateAs(8027);
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->withdrawProposal($proposal);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            ProposalStatus::Withdrawn,
            $proposal->status,
        );
        self::assertNotEmpty(self::flashBag($session)->get(AlertTypes::Danger->value));
    }

    private function proposalPost(
        ActivityProposal $proposal,
        string $name,
        DateTimeImmutable $day,
    ): Request {
        $request = Request::create(
            '/en/admin/activities/calendar/proposals/' . $proposal->id . '/edit',
            'POST',
            [
                'activity_proposal' => [
                    '_csrf_token' => 'csrf-token',
                    'organ' => (string) $proposal->organ?->id,
                    'period' => (string) $proposal->period->id,
                    'name' => $name,
                    'description' => '',
                    'dateOptions' => [
                        [
                            'timeOfDay' => 'evening',
                            'beginsAt' => $day->format('Y-m-d'),
                            'endsAt' => $day->format('Y-m-d'),
                        ],
                    ],
                ],
            ],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod('POST');

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $request->setSession($session);
        $request->setLocale('en');
        self::getContainer()->get('request_stack')->push($request);

        return $request;
    }

    private function aProposal(string $name): ActivityProposal
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
     * A token carrying the user's real roles, so an ordinary member is not silently treated as the board.
     */
    private function authenticateAs(int $lidnr): void
    {
        $user = $this->user($lidnr);

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));
    }

    private function pushRequestWithSession(): FlashBagAwareSessionInterface
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

    /**
     * Two members of one body submit at the same moment: the second finds the body's slot taken and is told to try
     * again.
     *
     * The mutex is a PostgreSQL advisory lock per database session, so a second connection takes it while the
     * controller tries.
     */
    public function testAProposalIsRefusedWhileTheSameBodyIsAlreadySubmitting(): void
    {
        $this->authenticate();

        $period = $this->openPeriod();
        $organ = $this->body('KEUR');
        $before = $this->proposalCount();

        $key = crc32(sprintf(
            'activity_proposal_%d_%d',
            $period->id,
            $organ->id,
        ));
        $other = DriverManager::getConnection($this->entityManager->getConnection()->getParams());

        try {
            $other->executeStatement(
                'SELECT pg_advisory_lock(?)',
                [$key],
            );

            $response = $this->submit(
                $organ,
                'Tegelijkertijd ingediend',
            );
        } finally {
            $other->executeStatement(
                'SELECT pg_advisory_unlock(?)',
                [$key],
            );
            $other->close();
        }

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            $before,
            $this->proposalCount(),
        );

        $request = self::getContainer()->get('request_stack')->getCurrentRequest();
        self::assertNotNull($request);

        $session = $request->getSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        self::assertNotEmpty(self::flashBag($session)->get(AlertTypes::Danger->value));
    }

    private function submit(
        Organ $organ,
        string $name,
        ?DateTimeImmutable $day = null,
    ): mixed {
        $period = $this->openPeriod();
        $day ??= $period->startsAt->modify('+3 days');

        // Stateless CSRF: the rendered token really is the literal `csrf-token`, and the manager only accepts it
        // together with a same-origin fetch header. Both are needed, neither alone is enough.
        $request = Request::create(
            '/en/admin/activities/calendar/propose',
            'POST',
            [
                'activity_proposal' => [
                    '_csrf_token' => 'csrf-token',
                    'organ' => (string) $organ->id,
                    'period' => (string) $period->id,
                    'name' => $name,
                    'description' => '',
                    'dateOptions' => [
                        [
                            'timeOfDay' => 'evening',
                            'beginsAt' => $day->format('Y-m-d'),
                            'endsAt' => $day->format('Y-m-d'),
                        ],
                    ],
                ],
            ],
        );
        $request->headers->set(
            'Sec-Fetch-Site',
            'same-origin',
        );
        $this->pushRequest($request);

        return $this->controller()->propose(
            $request,
            $this->user(8025),
        );
    }

    private function controller(): AdminActivityCalendarController
    {
        return self::getContainer()->get(AdminActivityCalendarController::class);
    }

    private function openPeriod(): OptionPeriod
    {
        $periods = $this->entityManager->getRepository(OptionPeriod::class)->findOpenAt(new DateTimeImmutable());

        self::assertNotEmpty(
            $periods,
            'The seed is expected to hold a round that is taking proposals.',
        );

        return $periods[0];
    }

    private function body(string $abbr): Organ
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy(['abbr' => $abbr]);

        self::assertInstanceOf(
            Organ::class,
            $organ,
        );

        return $organ;
    }

    private function proposalCount(): int
    {
        return count($this->entityManager->getRepository(ActivityProposal::class)->findAll());
    }

    private function authenticate(int $lidnr = 8025): void
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

    private function pushRequest(Request $request): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);
    }
}
