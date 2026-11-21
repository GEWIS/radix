<?php

declare(strict_types=1);

namespace App\Tests\Form\Activity;

use App\Entity\Activity\ActivityProposal;
use App\Entity\Activity\Enums\TimeOfDay;
use App\Entity\Activity\OptionPeriod;
use App\Entity\Decision\Organ;
use App\Entity\User\User;
use App\Form\Activity\ActivityProposalType;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function iterator_to_array;

/**
 * A body putting an activity forward: which bodies and rounds it is offered, and the refusals a submission that skipped
 * the editor runs into: a closed round, a body one may not act for, a body with nothing left, and days that are in the
 * past, backwards, outside the round, or beyond the limit of three.
 *
 * The seed is built for this: GETÉST is limited to two in the round that is open and has used both, KEUR has one left,
 * and a round whose days have passed is not taking proposals.
 */
#[CoversClass(ActivityProposalType::class)]
final class ActivityProposalTypeTest extends DatabaseTestCase
{
    public function testAPeriodIsNumberedTheWayTheDaysWerePutIn(): void
    {
        $form = $this->submit(
            $this->board(),
            [
                $this->day(80),
                $this->day(85),
            ],
        );

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );

        $proposal = $this->data($form);
        $options = iterator_to_array(
            $proposal->getDateOptions(),
            false,
        );

        self::assertCount(
            2,
            $options,
        );
        self::assertSame(
            1,
            $options[0]->position,
        );
        self::assertSame(
            2,
            $options[1]->position,
        );
    }

    public function testADayThatEndsBeforeItStartsIsRefused(): void
    {
        $form = $this->submit(
            $this->board(),
            [
                [
                    'timeOfDay' => TimeOfDay::Evening->value,
                    'beginsAt' => $this->date(85),
                    'endsAt' => $this->date(80),
                ],
            ],
        );

        self::assertStringContainsString(
            'A day cannot end before it starts.',
            (string) $form->getErrors(true),
        );
    }

    public function testAStretchOfDaysNeedsThePartOfTheDayThatSaysSo(): void
    {
        $stretch = [
            'timeOfDay' => TimeOfDay::Evening->value,
            'beginsAt' => $this->date(80),
            'endsAt' => $this->date(81),
        ];

        $form = $this->submit(
            $this->board(),
            [$stretch],
        );

        self::assertStringContainsString(
            'Multiple days can only be selected',
            (string) $form->getErrors(true),
        );

        $form = $this->submit(
            $this->board(),
            [['timeOfDay' => TimeOfDay::MultipleDays->value] + $stretch],
        );

        self::assertSame(
            '',
            (string) $form->getErrors(true),
        );
    }

    public function testADayThatHasAlreadyBeenIsRefused(): void
    {
        $form = $this->submit(
            $this->board(),
            [$this->day(-1)],
        );

        self::assertStringContainsString(
            'That day has already been.',
            (string) $form->getErrors(true),
        );
    }

    public function testADayTheRoundDoesNotCoverIsRefused(): void
    {
        $form = $this->submit(
            $this->board(),
            [$this->day(170)],
        );

        self::assertStringContainsString(
            'This round only covers',
            (string) $form->getErrors(true),
        );
    }

    public function testAnActivityNeedsAtLeastOneDay(): void
    {
        $form = $this->submit(
            $this->board(),
            [],
        );

        self::assertStringContainsString(
            'Put forward at least one day.',
            (string) $form->getErrors(true),
        );
    }

    /**
     * A proposal may hold three dates. The fourth is mapped onto the proposal and then refused by the collection's
     * constraint, so the form reports it on the field the body added the day to.
     */
    public function testNoMoreThanThreeDaysMayBePutForward(): void
    {
        $form = $this->submit(
            $this->board(),
            [
                $this->day(80),
                $this->day(85),
                $this->day(90),
                $this->day(95),
            ],
        );

        self::assertFalse($form->isValid());
        self::assertStringContainsString(
            'Put forward at most 3 days for one activity.',
            (string) $form->getErrors(true),
        );
    }

    public function testARoundThatHasStoppedTakingProposalsIsNotOffered(): void
    {
        $form = $this->submit(
            $this->member(8010),
            [$this->day(80)],
            $this->period('Q4 last year'),
            $this->organ('GETÉST'),
        );

        // The round is not among the ones a member may pick, so naming it anyway is a choice the form does not have.
        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('period')->getErrors(),
        );
    }

    /**
     * The board runs the calendar, so it may fill a round in after it has closed.
     */
    public function testTheBoardMayStillFillAClosedRound(): void
    {
        $form = $this->submit(
            $this->board(),
            [$this->day(-1)],
            $this->period('Q4 last year'),
        );

        self::assertStringNotContainsString(
            'This round is not taking proposals at the moment.',
            (string) $form->getErrors(true),
        );
    }

    public function testABodyOneMayNotActForIsNotOffered(): void
    {
        $form = $this->submit(
            $this->member(8010),
            [$this->day(80)],
            null,
            $this->organ('KEUR'),
        );

        // GETÉST is the only body this member is in, so KEUR is not one of the choices the form offers.
        self::assertFalse($form->isValid());
        self::assertNotCount(
            0,
            $form->get('organ')->getErrors(),
        );
    }

    public function testABodyThatHasUsedItsAllowanceIsToldWhichRuleSaidSo(): void
    {
        $form = $this->submit(
            $this->member(8010),
            [$this->day(80)],
            null,
            $this->organ('GETÉST'),
        );

        self::assertStringContainsString(
            'This body has already put forward everything it may in this round.',
            (string) $form->getErrors(true),
        );
    }

    /**
     * The board is over every limit, and is told what it is overriding rather than only that it cannot.
     */
    public function testTheBoardIsToldWhatItIsOverriding(): void
    {
        $form = $this->submit(
            $this->board(),
            [$this->day(80)],
            null,
            $this->organ('GETÉST'),
        );

        self::assertStringContainsString(
            'The limit is 2 and 2 have been used.',
            (string) $form->getErrors(true),
        );
    }

    public function testABodyWithRoomLeftMayPropose(): void
    {
        $form = $this->submit(
            $this->member(8025),
            [$this->day(80)],
            null,
            $this->organ('KEUR'),
        );

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
        self::assertSame(
            'KEUR',
            $this->data($form)->organ?->abbr,
        );
    }

    public function testTheBoardMayProposeWithoutABody(): void
    {
        $form = $this->submit(
            $this->board(),
            [$this->day(80)],
            null,
            null,
        );

        self::assertTrue(
            $form->isValid(),
            (string) $form->getErrors(true),
        );
        self::assertNull($this->data($form)->organ);
    }

    public function testAnOrdinaryMemberMustNameABody(): void
    {
        $form = $this->submit(
            $this->member(8010),
            [$this->day(80)],
            null,
            null,
        );

        self::assertStringContainsString(
            'Pick the body that would host this activity.',
            (string) $form->getErrors(true),
        );
    }

    /**
     * The token storage is shared by every test in the run, so a member left signed in here would be seen as the board
     * by another test.
     */
    #[Override]
    protected function tearDown(): void
    {
        self::getContainer()->get('security.token_storage')->setToken(null);

        parent::tearDown();
    }

    /**
     * @param list<array<string, mixed>> $days
     *
     * @return FormInterface<mixed>
     */
    private function submit(
        User $user,
        array $days,
        ?OptionPeriod $period = null,
        ?Organ $organ = null,
    ): FormInterface {
        $this->authenticate($user);

        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(
            FormFactoryInterface::class,
            $factory,
        );

        $form = $factory->create(
            ActivityProposalType::class,
            new ActivityProposal(),
            ['csrf_protection' => false],
        );

        $form->submit([
            'organ' => null === $organ ? null : (string) $organ->id,
            'period' => (string) ($period ?? $this->period('Q2 this year'))->id,
            'name' => 'Bowlingavond',
            'description' => null,
            'dateOptions' => $days,
        ]);

        return $form;
    }

    /**
     * @return array<string, mixed>
     */
    private function day(int $inDays): array
    {
        return [
            'timeOfDay' => TimeOfDay::Evening->value,
            'beginsAt' => $this->date($inDays),
            'endsAt' => $this->date($inDays),
        ];
    }

    private function date(int $inDays): string
    {
        return new DateTimeImmutable('today')->modify(
            ($inDays >= 0 ? '+' : '') . $inDays . ' days',
        )->format('Y-m-d');
    }

    private function period(string $name): OptionPeriod
    {
        $period = $this->entityManager->getRepository(OptionPeriod::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(
            OptionPeriod::class,
            $period,
            'The seed is expected to contain the round "' . $name . '".',
        );

        return $period;
    }

    private function organ(string $abbr): Organ
    {
        $organ = $this->entityManager->getRepository(Organ::class)->findOneBy(['abbr' => $abbr]);
        self::assertInstanceOf(
            Organ::class,
            $organ,
            'The seed is expected to contain the body "' . $abbr . '".',
        );

        return $organ;
    }

    private function board(): User
    {
        return $this->user(8025);
    }

    private function member(int $lidnr): User
    {
        return $this->user($lidnr);
    }

    private function user(int $lidnr): User
    {
        $user = $this->entityManager->find(
            User::class,
            $lidnr,
        );
        self::assertInstanceOf(
            User::class,
            $user,
        );

        return $user;
    }

    /**
     * A token carrying the user's real roles, so an ordinary member is not silently treated as the board.
     */
    private function authenticate(User $user): void
    {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function data(FormInterface $form): ActivityProposal
    {
        $data = $form->getData();
        self::assertInstanceOf(
            ActivityProposal::class,
            $data,
        );

        return $data;
    }
}
