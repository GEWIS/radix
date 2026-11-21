<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity\Admin;

use App\Entity\Activity\Activity;
use App\Entity\Activity\Enums\RecipientScope;
use App\Entity\Activity\Enums\SignupFilter;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupRole;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\User;
use App\Message\Activity\OrganiserAnnouncementEmail;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\Admin\SignupOverview;
use DateTimeImmutable;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_column;
use function array_filter;
use function count;

/**
 * The write half of the sign-up admin component: attendance, manual admission, roles, the email composer, and the
 * guards that refuse each outside its window. Every assertion checks what the action changed in the database or in
 * a dispatched message.
 */
final class SignupOverviewActionsTest extends DatabaseTestCase
{
    public function testMarkingPresenceFlipsItAndStampsTheList(): void
    {
        $this->authenticate();
        [
            $activity, $list
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => !$list->limitedCapacity
                && null !== self::firstMemberSignup($list),
        );
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $signup = self::firstMemberSignup($list);
        self::assertNotNull($signup);
        self::assertFalse($signup->present);

        $component = $this->overviewFor($activity->id ?? 0);
        $component->togglePresent($signup->id ?? 0);

        self::assertTrue($signup->present);
        // The list records that presence has been taken, which drives the public indicator and the review diff.
        self::assertTrue($list->presenceTaken);

        $component->togglePresent($signup->id ?? 0);
        self::assertFalse($signup->present);
    }

    public function testPresenceCannotBeMarkedOutsideTheWindow(): void
    {
        $this->authenticate();
        // The seeded workshop is in the past, so its attendance window has closed.
        $signup = self::firstAdmittedSignup($this->list(3));
        self::assertNotNull($signup);

        $component = $this->overviewFor(4);
        $component->togglePresent($signup->id ?? 0);

        self::assertFalse($signup->present);
        self::assertFalse($component->canMarkPresence());
    }

    public function testPresenceCannotBeMarkedForSomeoneStillWaiting(): void
    {
        $this->authenticate();
        [
            $activity, $list
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => $list->limitedCapacity
                && null !== self::firstWaitingSignup($list),
        );
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $signup = self::firstWaitingSignup($list);
        self::assertNotNull($signup);
        self::assertFalse($signup->drawn);

        $this->overviewFor($activity->id ?? 0)->togglePresent($signup->id ?? 0);

        // A waiting reader does not attend, so nothing changed.
        self::assertFalse($signup->present);
    }

    public function testAdmissionIsRefusedWhileTheDrawIsStillOpen(): void
    {
        $this->authenticate();
        [
            $activity, $list
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => $list->limitedCapacity
                && !$list->isDrawLocked()
                && !$list->allocationMethod->isManual()
                && null !== self::firstWaitingSignup($list),
        );
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $signup = self::firstWaitingSignup($list);
        self::assertNotNull($signup);

        $this->overviewFor($activity->id ?? 0)->toggleAdmission($signup->id ?? 0);

        self::assertFalse($signup->drawn);
    }

    public function testAdmissionMovesSomeoneOnAndOffALockedList(): void
    {
        $this->authenticate();
        [
            $activity, $list
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => $list->limitedCapacity
                && null !== self::firstWaitingSignup($list),
        );
        $list->drawnAt = new DateTimeImmutable('-1 day');
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $signup = self::firstWaitingSignup($list);
        self::assertNotNull($signup);
        self::assertFalse($signup->drawn);

        $component = $this->overviewFor($activity->id ?? 0);
        $component->toggleAdmission($signup->id ?? 0);
        self::assertTrue($signup->drawn);

        $signup->present = true;
        $component->toggleAdmission($signup->id ?? 0);
        self::assertFalse($signup->drawn);
        // Someone who is not admitted cannot have attended.
        self::assertFalse($signup->present);
    }

    public function testAdmissionIsRefusedOnAListWithNothingToBeAdmittedTo(): void
    {
        $this->authenticate();
        [
            $activity, $list
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => !$list->limitedCapacity
                && null !== self::firstMemberSignup($list),
        );
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $signup = self::firstMemberSignup($list);
        self::assertNotNull($signup);
        $before = $signup->drawn;

        $this->overviewFor($activity->id ?? 0)->toggleAdmission($signup->id ?? 0);

        self::assertSame(
            $before,
            $signup->drawn,
        );
    }

    public function testAssigningARoleTicksItAndAskingAgainClearsIt(): void
    {
        $this->authenticate();
        [
            $activity, $list
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => !$list->getRoles()->isEmpty()
                && !$list->isDrawLocked(),
        );
        // Roles are handed out once the list is closed, before the draw runs.
        $list->closeDate = new DateTimeImmutable('-1 hour');
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $signup = self::firstUnassignedSignup($list);
        self::assertNotNull($signup);
        $role = $list->getRoles()->first();
        self::assertInstanceOf(
            SignupRole::class,
            $role,
        );
        $component = $this->overviewFor($activity->id ?? 0);

        $component->assignRole(
            $signup->id ?? 0,
            $role->id ?? 0,
        );

        self::assertSame(
            $role,
            $signup->role,
        );

        $component->assignRole(
            $signup->id ?? 0,
            $role->id ?? 0,
        );

        self::assertNull($signup->role);
    }

    public function testRolesCannotBeHandedOutWhileTheListIsStillOpen(): void
    {
        $this->authenticate();
        [
            $activity, $list
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => !$list->getRoles()->isEmpty(),
        );
        $list->openDate = new DateTimeImmutable('-1 hour');
        $list->closeDate = new DateTimeImmutable('+1 hour');
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $signup = self::firstMemberSignup($list);
        self::assertNotNull($signup);
        $role = $list->getRoles()->first();
        self::assertInstanceOf(
            SignupRole::class,
            $role,
        );

        $this->overviewFor($activity->id ?? 0)->assignRole(
            $signup->id ?? 0,
            $role->id ?? 0,
        );

        self::assertNull($signup->role);
    }

    public function testTheComposerOpensOnEverybodyWhenNothingIsTicked(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);

        $component->openComposer();

        self::assertTrue($component->composerOpen);
        self::assertSame(
            RecipientScope::All,
            $component->scopeValue(),
        );
    }

    public function testTheComposerOpensOnTheTickedRows(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->selected = [self::firstMemberSignup($this->list(3))->id ?? 0];

        $component->openComposer();

        self::assertSame(
            RecipientScope::Selected,
            $component->scopeValue(),
        );
    }

    public function testAnOfferedScopeIsHonouredAndAnUnknownOneIsNot(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);

        $component->openComposer('external');
        self::assertSame(
            RecipientScope::External,
            $component->scopeValue(),
        );

        $component->openComposer('nonsense');
        self::assertSame(
            RecipientScope::All,
            $component->scopeValue(),
        );

        $component->setScope('nonsense');
        self::assertSame(
            RecipientScope::All,
            $component->scopeValue(),
        );
    }

    public function testOpeningTheComposerClearsWhatWasWrittenBefore(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->emailSubject = 'Half-written';
        $component->emailBody = 'Draft';

        $component->openComposer();

        self::assertSame(
            '',
            $component->emailSubject,
        );
        self::assertSame(
            '',
            $component->emailBody,
        );
    }

    public function testTheComposerClosesAgain(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->openComposer();

        $component->closeComposer();

        self::assertFalse($component->composerOpen);
    }

    public function testAttendanceModeOpensAndCloses(): void
    {
        $this->authenticate();
        $activity = $this->activity(4);
        $this->withWindow(
            $activity,
            '-1 hour',
            '+1 hour',
        );
        $component = $this->overviewFor(4);
        $component->filter = 'Jansen';

        $component->enterAttendance(3);
        self::assertSame(
            3,
            $component->attendanceListId,
        );
        self::assertSame(
            '',
            $component->filter,
            'The attendance list is a search of its own, so the organiser starts from the whole list.',
        );

        $component->exitAttendance();
        self::assertNull($component->attendanceListId);
    }

    public function testAttendanceModeIsNotEnteredOutsideTheWindow(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);

        $component->enterAttendance(3);

        self::assertNull($component->attendanceListId);
    }

    public function testAnOptionalColumnTogglesOnAndOff(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        self::assertTrue($component->columnShown('generation'));

        $component->toggleColumn('generation');
        self::assertFalse($component->columnShown('generation'));

        $component->toggleColumn('generation');
        self::assertTrue($component->columnShown('generation'));
    }

    public function testShowingAListReturnsToTheListReadingOfThatList(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->setMode(SignupOverview::MODE_PEOPLE);
        $component->composerOpen = true;

        $component->showList(3);

        self::assertFalse($component->isPeopleMode());
        self::assertSame(
            3,
            $component->activeListId,
        );
        self::assertFalse($component->composerOpen);
        self::assertSame(
            SignupFilter::All,
            $component->quickFilterValue(),
        );
        self::assertSame(
            3,
            $component->getActiveList()?->listId,
        );
    }

    public function testAnUnknownModeFallsBackToTheListReading(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);

        $component->setMode('nonsense');

        self::assertFalse($component->isPeopleMode());
    }

    public function testTheQuickFiltersOfferedFollowWhatIsOnScreen(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        self::assertSame(
            SignupFilter::forList(true),
            $component->getFilters(),
        );

        $component->setMode(SignupOverview::MODE_PEOPLE);
        self::assertSame(
            SignupFilter::forPeople(),
            $component->getFilters(),
        );

        $component->setQuickFilter('nonsense');
        self::assertSame(
            SignupFilter::All,
            $component->quickFilterValue(),
        );
    }

    public function testAnUnlimitedListOffersTheFiltersOfAnUnlimitedList(): void
    {
        $this->authenticate();
        [$activity] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => !$list->limitedCapacity,
        );

        $component = $this->overviewFor($activity->id ?? 0);
        $component->activeListId = $component->getActiveList()?->listId;

        self::assertSame(
            SignupFilter::forList(false),
            $component->getFilters(),
        );
    }

    public function testExternalsAreCountedApartFromTheMembers(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->setScope('all');

        $externals = array_filter(
            $component->getRecipients(),
            static fn (array $recipient): bool => $recipient['external'],
        );

        self::assertSame(
            count($externals),
            $component->getExternalRecipientCount(),
        );
        self::assertGreaterThan(
            0,
            count($externals),
            'The seeded workshop has an external on its list.',
        );
    }

    public function testSelectingWhatIsShownInThePeopleReadingTakesEverySignUp(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->setMode(SignupOverview::MODE_PEOPLE);

        $component->selectShown();

        // Everybody on the workshop: the four members and the external, each row carrying every sign-up on the list.
        self::assertCount(
            5,
            $component->selected,
        );

        $component->clearAll();
        self::assertSame(
            [],
            $component->selected,
        );
    }

    public function testAGroupWithNobodyInItIsReportedRatherThanSent(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->setScope('selected');
        $component->emailSubject = 'Hello';
        $component->emailBody = 'Nobody is ticked.';

        $component->sendEmail(3);

        self::assertSame(
            [],
            $this->bulkMessages(),
        );
        self::assertSame(
            AlertTypes::Warning->value,
            $component->feedbackType,
        );
    }

    public function testAMessageWithASubjectButNoBodyIsRefused(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->emailSubject = 'Only a subject';

        $component->sendEmail(3);

        self::assertSame(
            [],
            $this->bulkMessages(),
        );
        self::assertSame(
            AlertTypes::Warning->value,
            $component->feedbackType,
        );
    }

    public function testATestMessageGoesOnlyToTheOrganiser(): void
    {
        $user = $this->authenticate();
        $email = $user->member->email;
        self::assertNotNull($email);
        $component = $this->overviewFor(4);
        $component->emailSubject = 'About {{ACTIVITY_NAME}}';
        $component->emailBody = 'We meet at {{LOCATION}}.';

        $component->sendTest();

        $sent = $this->bulkMessages();
        self::assertCount(
            1,
            $sent,
        );
        self::assertSame(
            '[Test] About {{ACTIVITY_NAME}}',
            $sent[0]->getSubject(),
        );

        $recipients = $sent[0]->getRecipients();
        self::assertCount(
            1,
            $recipients,
        );
        self::assertSame(
            [$email],
            array_column(
                $recipients,
                'email',
            ),
        );
        // The activity's own values are replaced; a test has no sign-up behind it, so a question is replaced with its
        // own name, which shows where the answer will appear.
        self::assertSame(
            'Workshop',
            $recipients[0]['replacements']['ACTIVITY_NAME'],
        );
        self::assertSame(
            'Dietary requirements',
            $recipients[0]['replacements']['DIETARY_REQUIREMENTS'],
        );
        self::assertSame(
            AlertTypes::Success->value,
            $component->feedbackType,
        );
    }

    public function testATestIsRefusedWithoutAnEmailAddressToSendItTo(): void
    {
        $user = $this->authenticate();
        $component = $this->overviewFor(4);
        $component->emailSubject = 'Hello';
        $component->emailBody = 'Body';
        $user->member->email = null;

        $component->sendTest();

        self::assertSame(
            [],
            $this->bulkMessages(),
        );
        self::assertSame(
            AlertTypes::Warning->value,
            $component->feedbackType,
        );
    }

    public function testAnActionNamingAListOfAnotherActivityDoesNothing(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $component->selected = ['424242'];

        $component->selectAll(999999);
        self::assertSame(
            ['424242'],
            $component->selected,
        );

        $component->clearSelection(999999);
        self::assertSame(
            ['424242'],
            $component->selected,
        );

        $component->emailSubject = 'Hello';
        $component->emailBody = 'Body';
        $component->sendEmail(999999);
        self::assertSame(
            [],
            $this->bulkMessages(),
        );
    }

    public function testASignupOfAnotherActivityCannotBeToggled(): void
    {
        $this->authenticate();
        [
            $otherActivity, $otherList
        ] = $this->listWhere(
            static fn (Activity $activity, SignupList $list): bool => 4 !== $activity->id
                && null !== self::firstMemberSignup($list),
        );
        $signup = self::firstMemberSignup($otherList);
        self::assertNotNull($signup);
        $before = $signup->present;
        $this->withWindow(
            $otherActivity,
            '-1 hour',
            '+1 hour',
        );

        $this->overviewFor(4)->togglePresent($signup->id ?? 0);

        self::assertSame(
            $before,
            $signup->present,
        );
    }

    public function testThePeopleReadingIsGatedAsWell(): void
    {
        // 8005 is an active member who does not organise activity #9 and is not on the board.
        $this->authenticate(
            8005,
            ['ROLE_ACTIVE_MEMBER'],
        );

        $this->expectException(AccessDeniedException::class);
        $this->overviewFor(9)->getPeopleView();
    }

    /**
     * @param list<string> $roles
     */
    private function authenticate(
        int $lidnr = 8025,
        array $roles = ['ROLE_BOARD'],
    ): User {
        $user = $this->entityManager->getRepository(User::class)->find($lidnr);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $roles,
        ));

        return $user;
    }

    private function overviewFor(int $activityId): SignupOverview
    {
        $component = self::getContainer()->get(SignupOverview::class);
        $component->activity = $this->activity($activityId);

        return $component;
    }

    private function activity(int $activityId): Activity
    {
        $activity = $this->entityManager->getRepository(Activity::class)->find($activityId);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        return $activity;
    }

    private function list(int $listId): SignupList
    {
        $list = $this->entityManager->getRepository(SignupList::class)->find($listId);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );

        return $list;
    }

    /**
     * The first live sign-up list the predicate accepts, so a test does not depend on a fixture id that may move.
     *
     * @param callable(Activity, SignupList): bool $matches
     *
     * @return array{0: Activity, 1: SignupList}
     */
    private function listWhere(callable $matches): array
    {
        foreach ($this->entityManager->getRepository(Activity::class)->findAll() as $activity) {
            foreach ($activity->getLiveSignupLists() as $list) {
                if ($matches($activity, $list)) {
                    return [
                        $activity,
                        $list,
                    ];
                }
            }
        }

        self::fail('The seed has no sign-up list of the kind this test needs.');
    }

    /**
     * The sign-up windows are read from the clock, so a test that needs one moves the activity into it.
     */
    private function withWindow(
        Activity $activity,
        string $begin,
        string $end,
    ): void {
        $revision = $activity->getLiveRevision();
        self::assertNotNull($revision);
        $revision->beginTime = new DateTimeImmutable($begin);
        $revision->endTime = new DateTimeImmutable($end);
    }

    private static function firstMemberSignup(SignupList $list): ?UserSignup
    {
        foreach ($list->getSignUps() as $signup) {
            if ($signup instanceof UserSignup) {
                return $signup;
            }
        }

        return null;
    }

    private static function firstUnassignedSignup(SignupList $list): ?UserSignup
    {
        foreach ($list->getSignUps() as $signup) {
            if (
                $signup instanceof UserSignup
                && null === $signup->role
            ) {
                return $signup;
            }
        }

        return null;
    }

    private static function firstAdmittedSignup(SignupList $list): ?UserSignup
    {
        foreach ($list->getSignUps() as $signup) {
            if (
                $signup instanceof UserSignup
                && $signup->drawn
                && !$signup->present
            ) {
                return $signup;
            }
        }

        return null;
    }

    private static function firstWaitingSignup(SignupList $list): ?UserSignup
    {
        foreach ($list->getSignUps() as $signup) {
            if (
                $signup instanceof UserSignup
                && !$signup->drawn
            ) {
                return $signup;
            }
        }

        return null;
    }

    /**
     * @return OrganiserAnnouncementEmail[]
     */
    private function bulkMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.bulk');
        self::assertInstanceOf(
            InMemoryTransport::class,
            $transport,
        );

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!($message instanceof OrganiserAnnouncementEmail)) {
                continue;
            }

            $messages[] = $message;
        }

        return $messages;
    }
}
