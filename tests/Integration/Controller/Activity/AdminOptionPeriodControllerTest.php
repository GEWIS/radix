<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminOptionPeriodController;
use App\Entity\Activity\OptionPeriod;
use App\Entity\Activity\PeriodProposalLimit;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The board's rounds. Actions are invoked directly, as the other admin controller tests do: the app's session guard
 * logs out any synthetic browser session, so the `#[IsGranted]` board gate and the CSRF attributes are enforced by the
 * framework at the HTTP boundary rather than here.
 *
 * The one thing worth pinning beyond the ordinary create/edit is that a round with proposals cannot be removed out
 * from under the bodies that have reserved dates in it.
 */
final class AdminOptionPeriodControllerTest extends DatabaseTestCase
{
    public function testARoundWithProposalsIsNotRemoved(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $period = $this->aPeriodWithProposals();
        $id = $period->id;

        $response = $this->controller()->delete($period);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotNull($this->entityManager->getRepository(OptionPeriod::class)->find($id));
    }

    public function testAnEmptyRoundIsRemovedWithItsExceptions(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $period = new OptionPeriod();
        $period->name = 'A round nobody used';
        $period->submissionOpensAt = new DateTimeImmutable('-1 day');
        $period->submissionClosesAt = new DateTimeImmutable('+1 day');
        $period->startsAt = new DateTimeImmutable('+300 days');
        $period->endsAt = new DateTimeImmutable('+390 days');
        $this->entityManager->persist($period);

        $limit = new PeriodProposalLimit();
        $limit->period = $period;
        $limit->organ = $this->entityManager->getRepository(Organ::class)->findAll()[0];
        $limit->maxProposals = 1;
        $this->entityManager->persist($limit);
        $this->entityManager->flush();

        $periodId = $period->id;
        $limitId = $limit->id;

        $this->controller()->delete($period);

        self::assertNull($this->entityManager->getRepository(OptionPeriod::class)->find($periodId));
        self::assertNull($this->entityManager->getRepository(PeriodProposalLimit::class)->find($limitId));
    }

    public function testTheIndexListsEveryRound(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->index();

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'Q2 this year',
            (string) $response->getContent(),
        );
    }

    public function testTheCreateFormAsksForBothWindows(): void
    {
        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->create(new Request());

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'option_period',
            (string) $response->getContent(),
        );
    }

    public function testANewRoundIsOpened(): void
    {
        $this->authenticate();
        $request = $this->submitPeriod([
            'name' => 'Q3 next year',
            'submissionOpensAt' => '2030-01-01T12:00',
            'submissionClosesAt' => '2030-02-01T12:00',
            'startsAt' => '2030-03-01',
            'endsAt' => '2030-04-01',
            'defaultMaxProposals' => '3',
        ]);

        $response = $this->controller()->create($request);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        $stored = $this->entityManager->getRepository(OptionPeriod::class)->findOneBy([
            'name' => 'Q3 next year',
        ]);
        self::assertInstanceOf(
            OptionPeriod::class,
            $stored,
        );
        self::assertSame(
            3,
            $stored->defaultMaxProposals,
        );
        self::assertNotEmpty(self::flashBag($request)->get(AlertTypes::Success->value));
    }

    public function testARoundWithAOneLetterNameIsRefused(): void
    {
        $this->authenticate();
        $request = $this->submitPeriod([
            'name' => 'Q',
            'submissionOpensAt' => '2030-01-01T12:00',
            'submissionClosesAt' => '2030-02-01T12:00',
            'startsAt' => '2030-03-01',
            'endsAt' => '2030-04-01',
        ]);

        $response = $this->controller()->create($request);

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'The name should be at least',
            (string) $response->getContent(),
        );
        self::assertNull($this->entityManager->getRepository(OptionPeriod::class)->findOneBy([
            'name' => 'Q',
        ]));
    }

    public function testARoundWhoseWindowRunsBackwardsIsRefused(): void
    {
        $this->authenticate();
        $request = $this->submitPeriod([
            'name' => 'Backwards round',
            'submissionOpensAt' => '2030-03-01T12:00',
            'submissionClosesAt' => '2030-02-01T12:00',
            'startsAt' => '2030-04-01',
            'endsAt' => '2030-03-01',
        ]);

        $response = $this->controller()->create($request);

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );

        $content = (string) $response->getContent();
        self::assertStringContainsString(
            'Proposing must close after it opens',
            $content,
        );
        self::assertStringContainsString(
            'The last day of the round must not be before the first',
            $content,
        );
    }

    public function testTheEditFormIsFilledInFromTheRound(): void
    {
        $period = $this->openPeriod();

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->edit(
            new Request(),
            $period,
        );

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'Q2 this year',
            (string) $response->getContent(),
        );
    }

    public function testARoundCanBeRenamed(): void
    {
        $period = $this->openPeriod();

        $this->authenticate();
        $request = $this->submitPeriod(
            [
                'name' => 'Q2 this year, renamed',
                'submissionOpensAt' => $period->submissionOpensAt->format('Y-m-d\TH:i'),
                'submissionClosesAt' => $period->submissionClosesAt->format('Y-m-d\TH:i'),
                'startsAt' => $period->startsAt->format('Y-m-d'),
                'endsAt' => $period->endsAt->format('Y-m-d'),
            ],
            '/en/admin/activities/calendar/periods/' . $period->id . '/edit',
        );

        $response = $this->controller()->edit(
            $request,
            $period,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            'Q2 this year, renamed',
            $period->name,
        );
        self::assertNotEmpty(self::flashBag($request)->get(AlertTypes::Success->value));
    }

    public function testTheLimitsPageListsTheExceptionsOfTheRound(): void
    {
        $period = $this->openPeriod();

        $this->authenticate();
        $this->pushRequestWithSession();

        $response = $this->controller()->limits(
            new Request(),
            $period,
        );

        self::assertTrue($response->isSuccessful());
        self::assertStringContainsString(
            'GETÉST',
            (string) $response->getContent(),
        );
    }

    public function testAnExceptionForOneRoundCanBeRecorded(): void
    {
        $period = $this->openPeriod();
        $organ = $this->anActiveOrganWithoutAPeriodLimit($period);

        $this->authenticate();
        $request = $this->submitLimit(
            [
                'organ' => (string) $organ->id,
                'maxProposals' => '1',
            ],
            $period,
        );

        $response = $this->controller()->limits(
            $request,
            $period,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        $stored = $this->entityManager->getRepository(PeriodProposalLimit::class)->findOneBy([
            'period' => $period,
            'organ' => $organ,
        ]);
        self::assertInstanceOf(
            PeriodProposalLimit::class,
            $stored,
        );
        self::assertSame(
            1,
            $stored->maxProposals,
        );
        self::assertNotEmpty(self::flashBag($request)->get(AlertTypes::Success->value));
    }

    public function testANegativeExceptionIsRefused(): void
    {
        $period = $this->openPeriod();
        $organ = $this->anActiveOrganWithoutAPeriodLimit($period);

        $this->authenticate();
        $request = $this->submitLimit(
            [
                'organ' => (string) $organ->id,
                'maxProposals' => '-1',
            ],
            $period,
        );

        $response = $this->controller()->limits(
            $request,
            $period,
        );

        self::assertNotInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            'negative number of activities',
            (string) $response->getContent(),
        );
    }

    public function testAnExceptionCanBeRemovedAgain(): void
    {
        $period = $this->openPeriod();

        $limit = $this->entityManager->getRepository(PeriodProposalLimit::class)->findOneBy([
            'period' => $period,
        ]);
        self::assertInstanceOf(
            PeriodProposalLimit::class,
            $limit,
            'The seed is expected to give a body an exception in the open round.',
        );
        $id = $limit->id;

        $this->authenticate();
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->deleteLimit(
            $period,
            $limit,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNull($this->entityManager->getRepository(PeriodProposalLimit::class)->find($id));
        self::assertNotEmpty(self::flashBag($session)->get(AlertTypes::Success->value));
    }

    /**
     * @param array<string, string> $fields
     */
    private function submitPeriod(
        array $fields,
        string $path = '/en/admin/activities/calendar/periods/create',
    ): Request {
        return $this->submit(
            'option_period',
            $fields,
            $path,
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function submitLimit(
        array $fields,
        OptionPeriod $period,
    ): Request {
        return $this->submit(
            'period_proposal_limit',
            $fields,
            '/en/admin/activities/calendar/periods/' . $period->id . '/limits',
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(
        string $formName,
        array $fields,
        string $path,
    ): Request {
        $fields['_csrf_token'] = 'csrf-token';

        $request = Request::create(
            $path,
            'POST',
            [$formName => $fields],
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

    private function openPeriod(): OptionPeriod
    {
        $periods = $this->entityManager->getRepository(OptionPeriod::class)->findOpenAt(new DateTimeImmutable());
        self::assertNotEmpty(
            $periods,
            'The seed is expected to hold a round that is taking proposals.',
        );

        return $periods[0];
    }

    private function anActiveOrganWithoutAPeriodLimit(OptionPeriod $period): Organ
    {
        $limited = $this->entityManager->getRepository(PeriodProposalLimit::class)->findOneBy([
            'period' => $period,
        ])?->organ;

        foreach ($this->entityManager->getRepository(Organ::class)->findActive() as $organ) {
            if ($limited === $organ) {
                continue;
            }

            return $organ;
        }

        self::fail('The seed is expected to contain more than one active body.');
    }

    private function controller(): AdminOptionPeriodController
    {
        return self::getContainer()->get(AdminOptionPeriodController::class);
    }

    private function aPeriodWithProposals(): OptionPeriod
    {
        foreach ($this->entityManager->getRepository(OptionPeriod::class)->findAll() as $period) {
            if ($period->getProposals()->isEmpty()) {
                continue;
            }

            return $period;
        }

        self::fail('The seed is expected to hold a round with proposals in it.');
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
}
