<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity\Admin;

use App\Entity\Activity\Activity;
use App\Entity\Activity\ExternalSignup;
use App\Entity\Activity\Signup;
use App\Entity\Activity\SignupList;
use App\Entity\Activity\UserSignup;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Decision\Member;
use App\Entity\User\User;
use App\Message\Activity\OrganiserAnnouncementEmail;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\Admin\SignupOverview;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_column;
use function array_map;
use function count;

/**
 * The parts of the sign-up administration the list-mode tests leave alone: the people overview's scope list and
 * recipients, the no-change paths of the per-row actions, the column toggles, and the composer's refusals. A person in
 * two sign-up lists is the case the per-person mail exists for, so it is built here on the two-list activity rather
 * than assumed from the seed.
 */
final class SignupOverviewRemainderTest extends DatabaseTestCase
{
    /** The Gala: one limited list (#6) with four subscribers. */
    private const int GALA = 9;

    /** The Excursion: a closed, not yet drawn limited list. */
    private const int EXCURSION = 13;

    /** A two-list activity with no subscribers of its own. */
    private const int SYMPOSIUM = 10;

    /** A first-come-first-served list (list #16) that has not been drawn. */
    private const int A2 = 18;

    /** A meeting of the association without any sign-up list. */
    private const int NO_LISTS = 5;

    /** The cancelled activity, whose announcement carries the marker. */
    private const int CANCELLED = 14;

    public function testPeopleModeOffersEveryScopeAcrossTheLists(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::GALA);
        $component->setMode(SignupOverview::MODE_PEOPLE);

        $audiences = $component->getAudiences();

        self::assertSame(
            [
                'all',
                'multi',
                'waitlisted',
                'external',
                'present',
                'selected',
            ],
            array_map(
                static fn (array $audience): string => $audience['scope']->value,
                $audiences,
            ),
        );
        self::assertSame(
            4,
            $audiences[0]['count'],
        );
        // A single-list activity has nobody in more than one part of it.
        self::assertSame(
            0,
            $audiences[1]['count'],
        );
    }

    /**
     * People mode does not offer the per-list admission scopes, but the prop is writable, so a stale one still
     * resolves through the per-list expectation rather than selecting nobody.
     */
    public function testAStaleExpectedScopeInPeopleModeResolvesPerList(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::A2);
        $component->setMode(SignupOverview::MODE_PEOPLE);

        $component->setScope('expected-admitted');
        self::assertCount(
            4,
            $component->getRecipients(),
        );

        $component->setScope('expected-waitlisted');
        self::assertCount(
            7,
            $component->getRecipients(),
        );
    }

    public function testThePeopleOverviewResolvesEverySettledScope(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::GALA);
        $component->setMode(SignupOverview::MODE_PEOPLE);

        foreach (
            [
                'all',
                'multi',
                'waitlisted',
                'external',
                'present',
                'selected',
            ] as $scope
        ) {
            $component->setScope($scope);
            $recipients = $component->getRecipients();
            self::assertIsArray($recipients);
        }

        // The Gala's conditional draw has not run, so every subscriber is still waiting.
        $component->setScope('waitlisted');
        self::assertCount(
            4,
            $component->getRecipients(),
        );
    }

    public function testAnUndrawnLimitedListMailsEveryoneAsWaiting(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::A2);
        $component->setMode(SignupOverview::MODE_PEOPLE);
        $component->setScope('waitlisted');

        self::assertCount(
            11,
            $component->getRecipients(),
        );
    }

    public function testAPersonInTwoListsIsMailedOnce(): void
    {
        $this->authenticate();
        $activity = $this->activity(self::SYMPOSIUM);
        $member = $this->member(8005);

        foreach ($activity->getLiveSignupLists() as $list) {
            $signup = new UserSignup();
            $signup->signupList = $list;
            $signup->user = $member;
            $signup->drawn = !$list->limitedCapacity;
            $this->entityManager->persist($signup);
        }

        $this->entityManager->flush();

        $component = $this->overviewFor(self::SYMPOSIUM);
        $component->setMode(SignupOverview::MODE_PEOPLE);
        $component->setScope('all');

        $recipients = $component->getRecipients();
        self::assertCount(
            1,
            $recipients,
        );
        self::assertSame(
            $member->email,
            $recipients[0]['email'],
        );
    }

    public function testAnUnconfirmedExternalIsNotCountedOrMailed(): void
    {
        $this->authenticate();
        $list = $this->listOf(
            self::GALA,
            6,
        );
        $confirmed = 0;
        foreach ($list->getSignUps() as $signup) {
            if (
                $signup instanceof ExternalSignup
                && null === $signup->verifiedAt
            ) {
                continue;
            }

            ++$confirmed;
        }

        $pending = new ExternalSignup();
        $pending->signupList = $list;
        $pending->setFullName('Pending Visitor');
        $pending->setEmail('pending@example.com');
        $pending->drawn = true;
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $component = $this->overviewFor(self::GALA);
        self::assertSame(
            $confirmed,
            $component->getActiveList()?->subscriberCount,
        );
        self::assertNotContains(
            'pending@example.com',
            array_column(
                $component->getRecipients(),
                'email',
            ),
        );
    }

    public function testAnActivityWithoutAListOffersNothingToCompose(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::NO_LISTS);

        self::assertSame(
            [],
            $component->getListViews(),
        );
        self::assertNull($component->getActiveList());
        self::assertSame(
            [],
            $component->getRecipients(),
        );

        $scopes = array_map(
            static fn (array $audience): string => $audience['scope']->value,
            $component->getAudiences(),
        );
        self::assertContains(
            'all',
            $scopes,
        );
        self::assertContains(
            'selected',
            $scopes,
        );
        // No list to take a question from, so only the activity's own placeholders are offered.
        self::assertArrayNotHasKey(
            'SIGNUPLIST_NAME',
            $component->getAboutPlaceholders(),
        );
        self::assertSame(
            [],
            $component->getQuestionPlaceholders(),
        );
    }

    public function testAdmittingAnUnknownSignupDoesNothing(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::A2);

        $component->toggleAdmission(999999);

        self::assertNull($component->feedback);
    }

    public function testAssigningARoleToAnUnknownSignupDoesNothing(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::EXCURSION);

        $component->assignRole(
            999999,
            1,
        );

        self::assertNull($component->feedback);
    }

    public function testAssigningARoleThatIsNotOfferedLeavesTheSignupAlone(): void
    {
        $this->authenticate();
        // Activity #16's list #14 is closed, undrawn and still inside the admission window, and it has roles.
        $component = $this->overviewFor(16);
        $list = $this->listOf(
            16,
            14,
        );
        $signup = $list->getSignUps()->first();
        self::assertInstanceOf(
            Signup::class,
            $signup,
        );
        $roleBefore = $signup->role;

        $component->assignRole(
            (int) $signup->id,
            999999,
        );

        self::assertSame(
            $roleBefore,
            $signup->role,
        );
    }

    /**
     * A draw is the board's decision, so an organiser inside the viewing window may not run one.
     */
    public function testAnOrganiserWhoIsNotOnTheBoardCannotDraw(): void
    {
        // 8010 organises the Gala but is not on the board.
        $this->authenticate(
            8010,
            ['ROLE_ACTIVE_MEMBER'],
        );
        $component = $this->overviewFor(self::GALA);

        $this->expectException(AccessDeniedException::class);
        $component->drawFirstCome(6);
    }

    public function testDrawingAnUnknownListDoesNothing(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::A2);

        $component->drawFirstCome(999999);

        self::assertNull($component->feedback);
    }

    public function testDrawingAListThatIsAlreadyDrawnIsRefused(): void
    {
        $this->authenticate();
        // G3 is the same first-come-first-served list as G2, after its draw.
        $component = $this->overviewFor(37);
        $list = $this->activity(37)->getLiveSignupLists()->first();
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );

        $component->drawFirstCome((int) $list->id);

        self::assertSame(
            AlertTypes::Warning->value,
            $component->feedbackType,
        );
        self::assertStringContainsString(
            'The draw could not be run',
            (string) $component->feedback,
        );
    }

    public function testSelectingEverythingTwiceKeepsOneEntryPerRow(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::GALA);

        $component->selectAll(6);
        $first = $component->selected;
        $component->selectAll(6);

        self::assertSame(
            $first,
            $component->selected,
        );
    }

    public function testSelectingTheShownRowsTwiceKeepsOneEntryPerRow(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::GALA);

        $component->selectShown();
        $first = $component->selected;
        $component->selectShown();

        self::assertSame(
            $first,
            $component->selected,
        );
    }

    public function testHidingAColumnLeavesTheOtherHiddenColumnsAlone(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::GALA);

        $component->toggleColumn('generation');
        $component->toggleColumn('signedUpAt');
        $component->toggleColumn('generation');

        self::assertContains(
            'signedUpAt',
            $component->hiddenColumns,
        );
        self::assertNotContains(
            'generation',
            $component->hiddenColumns,
        );
    }

    public function testHidingAFieldColumnLeavesTheOtherHiddenFieldsAlone(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(4);
        $list = $this->listOf(
            4,
            3,
        );
        $fields = $list->getFields()->toArray();
        self::assertGreaterThanOrEqual(
            2,
            count($fields),
        );

        $component->toggleFieldColumn((int) $fields[0]->id);
        $component->toggleFieldColumn((int) $fields[1]->id);
        $component->toggleFieldColumn((int) $fields[0]->id);

        self::assertContains(
            (int) $fields[1]->id,
            $component->hiddenFields,
        );
        self::assertNotContains(
            (int) $fields[0]->id,
            $component->hiddenFields,
        );
    }

    public function testSendingToEveryoneWithoutAMessageSendsNothing(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::GALA);
        $component->setMode(SignupOverview::MODE_PEOPLE);
        $component->emailBody = 'A body, but no subject.';

        $component->sendToPeople();

        self::assertSame(
            [],
            $this->bulkMessages(),
        );
        self::assertSame(
            AlertTypes::Warning->value,
            $component->feedbackType,
        );
    }

    public function testSendingATestWithoutAMessageSendsNothing(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::GALA);

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

    public function testACancelledActivityIsAnnouncedWithItsMarker(): void
    {
        $this->authenticate();
        $component = $this->overviewFor(self::CANCELLED);
        $component->emailSubject = 'About the gala';
        $component->emailBody = 'A practical note.';

        $component->sendEmail(12);

        $messages = $this->bulkMessages();
        self::assertCount(
            1,
            $messages,
        );
        self::assertStringStartsWith(
            '[CANCELLED] ',
            $messages[0]->getActivityName(),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function authenticate(
        int $lidnr = 8025,
        array $roles = ['ROLE_BOARD'],
    ): void {
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
    }

    private function overviewFor(int $activityId): SignupOverview
    {
        $component = self::getContainer()->get(SignupOverview::class);
        $component->activity = $this->activity($activityId);

        return $component;
    }

    private function activity(int $id): Activity
    {
        $activity = $this->entityManager->getRepository(Activity::class)->find($id);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        return $activity;
    }

    private function listOf(
        int $activityId,
        int $listId,
    ): SignupList {
        foreach ($this->activity($activityId)->getLiveSignupLists() as $list) {
            if ($list->id === $listId) {
                return $list;
            }
        }

        self::fail('The seed is expected to contain sign-up list ' . $listId . '.');
    }

    private function member(int $lidnr): Member
    {
        $member = $this->entityManager->find(
            Member::class,
            $lidnr,
        );
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        return $member;
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
            if (!$message instanceof OrganiserAnnouncementEmail) {
                continue;
            }

            $messages[] = $message;
        }

        return $messages;
    }
}
