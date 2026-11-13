<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity\Admin;

use App\Entity\Activity\Activity;
use App\Entity\Activity\Enums\RecipientScope;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\User\User;
use App\Message\Activity\OrganiserAnnouncementEmail;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Activity\Admin\SignupOverview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

use function array_column;
use function array_combine;
use function array_keys;
use function array_map;
use function count;
use function strval;

/**
 * The sign-up admin component is the first live component that writes to the database and dispatches mail, so it
 * re-asserts access on every action rather than trusting the page that embedded it. These exercise it as the framework
 * does (the real component instance with its real services) after authenticating the current user on the token
 * storage, which is what its {@see SignupOverview::assertAccess()} reads.
 *
 * Driving it over the live-component HTTP endpoint is not viable here: the app's session guard force-logs-out any
 * session not backed by a managed-session row, so a synthetic browser session never survives. The class-level
 * `#[IsGranted(SudoVoter::ATTRIBUTE)]` is therefore enforced by the framework at that HTTP layer, not exercised here;
 * the substantive per-action authorisation ({@see SignupOverview::assertAccess()}, board-only draws) is.
 *
 * Activity #9 (the Gala) has one limited list (#6) with four subscribers; activity #13 (the Excursion) has a closed,
 * not-yet-drawn limited list (#11, capacity 2, four sign-ups) ready for a draw. The allocation-matrix activities
 * cover the expected admittees / waiting list of a first-come-first-served list before its draw: #18 (A2) ranks
 * by membership tier, #24 (C2) by cohort, #36 (G2) is an unranked first-come-first-served list; all three are
 * closed and not yet drawn, while #37 (G3) is the same list, drawn, and #33 (F2) a not-yet-drawn conditional draw.
 */
final class SignupOverviewTest extends DatabaseTestCase
{
    public function testSelectAllThenClearSelectionScopesToTheList(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);

        $component->selectAll(6);
        self::assertCount(
            4,
            $component->selected,
        );

        $component->clearSelection(6);
        self::assertCount(
            0,
            $component->selected,
        );
    }

    public function testSelectingAllShownStoresTheIdsInTheFormTheCheckboxesUse(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);

        $component->selectShown();

        $ids = $this->signupIds(6);
        self::assertCount(
            count($ids),
            $component->selected,
        );
        foreach ($component->selected as $stored) {
            // The client merges checkbox state by strict string comparison against the checkbox value attribute,
            // which is the id as a string; a stored int would never be removed by an untick.
            self::assertIsString($stored);
            self::assertContains(
                $stored,
                array_map(
                    static fn (int $id): string => strval($id),
                    $ids,
                ),
            );
        }
    }

    public function testClearingAListLeavesForeignIdsInTheirStoredForm(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);

        // The string form a checkbox delivers, for an id that belongs to no list of this activity.
        $component->selected = ['424242'];

        $component->clearSelection(6);

        self::assertSame(
            ['424242'],
            $component->selected,
        );
    }

    public function testToggleFieldColumnFlipsHiddenState(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);

        $component->toggleFieldColumn(2);
        self::assertContains(
            2,
            $component->hiddenFields,
        );

        $component->toggleFieldColumn(2);
        self::assertNotContains(
            2,
            $component->hiddenFields,
        );
    }

    public function testSendEmailDispatchesAnAnnouncementToTheListRecipients(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);
        $component->emailSubject = 'See you at the Gala';
        $component->emailBody = 'Doors open at 17:00.';

        $component->sendEmail(6);

        $sent = $this->bulkMessages();
        self::assertCount(
            1,
            $sent,
        );
        // All four subscribers have an email, so the default "everyone" scope reaches all of them.
        self::assertCount(
            4,
            $sent[0]->getRecipients(),
        );
    }

    public function testSendEmailWithoutASubjectDispatchesNothing(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);
        $component->emailBody = 'A body, but no subject.';

        $component->sendEmail(6);

        self::assertSame(
            [],
            $this->bulkMessages(),
        );
        self::assertSame(
            AlertTypes::Warning->value,
            $component->feedbackType,
        );
    }

    public function testDrawAdmitsUpToCapacityAndLocksTheList(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(13);

        $component->drawLottery(11);

        $list = $this->entityManager->getRepository(SignupList::class)->find(11);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );
        // Capacity is two, so exactly two of the four sign-ups are admitted and the draw is locked with an audit stamp.
        $drawn = 0;
        foreach ($list->getSignUps() as $signup) {
            if (!$signup->drawn) {
                continue;
            }

            ++$drawn;
        }

        self::assertSame(
            2,
            $drawn,
        );
        self::assertNotNull($list->drawnAt);
        self::assertNotNull($list->drawnBy);
    }

    public function testTheListReadingDrawsTheRailAndTheTable(): void
    {
        $this->authenticate(['ROLE_BOARD']);

        $html = $this->render(9);

        self::assertStringContainsString(
            'One list at a time',
            $html,
        );
        self::assertStringContainsString(
            'card h-100 w-100 text-start border-gewis-primary',
            $html,
        );
        self::assertStringContainsString(
            'Select all shown',
            $html,
        );
        self::assertStringContainsString(
            'Everyone',
            $html,
        );
    }

    public function testThePeopleReadingHasOneRowPerPersonAndAColumnPerList(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);

        $component->setMode(SignupOverview::MODE_PEOPLE);
        $people = $component->getPeopleView();

        self::assertCount(
            1,
            $people->lists,
        );
        self::assertSame(
            4,
            $people->peopleCount,
        );
        self::assertSame(
            0,
            $people->multiCount,
        );

        $html = $this->render(
            9,
            ['mode' => SignupOverview::MODE_PEOPLE],
        );
        self::assertStringContainsString(
            'Everyone, side by side',
            $html,
        );
        self::assertStringContainsString(
            '1 / 1',
            $html,
        );
    }

    public function testAQuickFilterNarrowsTheRowsButNotTheCounts(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);

        $component->setQuickFilter('external');
        $list = $component->getActiveList();

        self::assertNotNull($list);
        self::assertSame(
            4,
            $list->subscriberCount,
        );
        self::assertSame(
            $list->externalCount,
            $list->shownCount,
        );
    }

    public function testSendingToEveryoneReachesEachPersonOnce(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(9);
        $component->setMode(SignupOverview::MODE_PEOPLE);
        $component->emailSubject = 'See you at the Gala';
        $component->emailBody = 'Doors open at 17:00.';

        $component->sendToPeople();

        $sent = $this->bulkMessages();
        self::assertCount(
            1,
            $sent,
        );
        self::assertCount(
            4,
            $sent[0]->getRecipients(),
        );
    }

    public function testAnActionIsDeniedForANonOwnerNonBoardMember(): void
    {
        // 8005 is an active member but does not organise activity #9 and is not on the board, so the per-action access
        // check rejects the request even though the embedding page would have been gated separately.
        $this->authenticate(
            ['ROLE_ACTIVE_MEMBER'],
            8005,
        );
        $component = $this->overviewFor(9);

        $this->expectException(AccessDeniedException::class);
        $component->selectAll(6);
    }

    public function testTheNoShowGroupReachesOnlyTheAdmitteesWhoDidNotTurnUp(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(4);
        $component->setScope('no-show');
        $component->emailSubject = 'We missed you';
        $component->emailBody = 'You had a place but were not there.';

        $component->sendEmail(3);

        $sent = $this->bulkMessages();
        self::assertCount(
            1,
            $sent,
        );
        // Two of the three admittees were marked present, so only 8015 remains.
        self::assertSame(
            ['8015@example.com'],
            array_column(
                $sent[0]->getRecipients(),
                'email',
            ),
        );
    }

    public function testThePlaceholdersOfferedAreTheActivityAndTheQuestionsOfTheListOnScreen(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(4);

        self::assertSame(
            [
                'ACTIVITY_NAME',
                'SIGNUPLIST_NAME',
                'ORGAN_NAME',
                'ORGAN_ABBR',
                'COMPANY_NAME',
                'LOCATION',
                'COSTS',
                'BEGIN_TIME',
                'END_TIME',
            ],
            array_keys($component->getAboutPlaceholders()),
        );
        self::assertSame(
            [
                'DIETARY_REQUIREMENTS' => 'Dietary requirements',
                'T_SHIRT_SIZE' => 'T-shirt size',
            ],
            $component->getQuestionPlaceholders(),
        );
    }

    public function testAcrossListsOnlyWhatTheActivityAnswersForIsOffered(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(4);
        $component->setMode(SignupOverview::MODE_PEOPLE);

        // The activity is the same whichever sign-up list a person is in; the sign-up lists and their questions
        // are not.
        self::assertArrayNotHasKey(
            'SIGNUPLIST_NAME',
            $component->getAboutPlaceholders(),
        );
        self::assertSame(
            [],
            $component->getQuestionPlaceholders(),
        );
    }

    public function testEachRecipientCarriesTheirOwnAnswerToEveryPlaceholder(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(4);
        $component->emailSubject = 'About {{ACTIVITY_NAME}}';
        $component->emailBody = 'We are in {{LOCATION}}; we have you down for {{DIETARY_REQUIREMENTS}} '
            . 'and a {{T_SHIRT_SIZE}}.';

        $component->sendEmail(3);

        $sent = $this->bulkMessages();
        self::assertCount(
            1,
            $sent,
        );

        $replacements = array_combine(
            array_column(
                $sent[0]->getRecipients(),
                'email',
            ),
            array_column(
                $sent[0]->getRecipients(),
                'replacements',
            ),
        );

        // A chosen option is replaced with the chosen word, and an unanswered question with an empty string rather
        // than a missing key, so the rest of the sentence is unaffected. The activity's own values are the same for
        // everybody; only the answers differ.
        self::assertSame(
            [
                'None',
                'S',
            ],
            [
                $replacements['8015@example.com']['DIETARY_REQUIREMENTS'],
                $replacements['8015@example.com']['T_SHIRT_SIZE'],
            ],
        );
        self::assertSame(
            [
                '',
                'L',
            ],
            [
                $replacements['8006@example.com']['DIETARY_REQUIREMENTS'],
                $replacements['8006@example.com']['T_SHIRT_SIZE'],
            ],
        );
        // This activity has no organising body, which makes it the board's, and no organising company.
        self::assertSame(
            [
                'Workshop',
                'Participants',
                'the board',
                '',
                'Room 2',
            ],
            [
                $replacements['8006@example.com']['ACTIVITY_NAME'],
                $replacements['8006@example.com']['SIGNUPLIST_NAME'],
                $replacements['8006@example.com']['ORGAN_NAME'],
                $replacements['8006@example.com']['COMPANY_NAME'],
                $replacements['8006@example.com']['LOCATION'],
            ],
        );
    }

    public function testTheComposerOffersThePlaceholdersOfTheListItIsWritingTo(): void
    {
        $this->authenticate(['ROLE_BOARD']);

        $html = $this->render(
            4,
            ['composerOpen' => true],
        );

        self::assertStringContainsString(
            'data-announcement-placeholder-token-param="{{DIETARY_REQUIREMENTS}}"',
            $html,
        );
        self::assertStringContainsString(
            'Write in English',
            $html,
        );
    }

    public function testAMessageWithAPlaceholderTheListDoesNotHaveIsNotSent(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(4);
        $component->emailSubject = 'About the workshop';
        $component->emailBody = 'We have you down for {{FAVOURITE_COLOUR}}.';

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

    public function testTheExpectedScopesAreOfferedWhileTheFirstComeFirstServedDrawIsStillOpen(): void
    {
        $this->authenticate(['ROLE_BOARD']);

        // A1 is open, A2 and G2 are closed; none has been drawn, so all three offer the expectation.
        $scopes = [];
        foreach ([17, 18, 36] as $activityId) {
            $component = $this->overviewFor($activityId);
            $scopes = array_column(
                $component->getAudiences(),
                'scope',
            );
            self::assertContains(
                RecipientScope::ExpectedAdmitted,
                $scopes,
            );
            self::assertContains(
                RecipientScope::ExpectedWaitlisted,
                $scopes,
            );
            // Admission is not settled, so the settled scopes are not offered next to them.
            self::assertNotContains(
                RecipientScope::Admitted,
                $scopes,
            );
            self::assertNotContains(
                RecipientScope::Waitlisted,
                $scopes,
            );
        }

        // A2 (list #16): capacity four of eleven subscribers, so the expectation splits 4 / 7.
        $component = $this->overviewFor(18);
        foreach ($component->getAudiences() as $audience) {
            if (
                RecipientScope::ExpectedAdmitted === $audience['scope']
            ) {
                self::assertSame(
                    4,
                    $audience['count'],
                );
            }

            if (
                RecipientScope::ExpectedWaitlisted !== $audience['scope']
            ) {
                continue;
            }

            self::assertSame(
                7,
                $audience['count'],
            );
        }

        // The composer renders the offered scopes as its audience choices.
        $html = $this->render(
            18,
            ['composerOpen' => true],
        );
        self::assertStringContainsString(
            'Expected admittees',
            $html,
        );
        self::assertStringContainsString(
            'Expected waiting list',
            $html,
        );
    }

    public function testTheExpectedScopesAreNotOfferedOnceTheDrawIsLocked(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(37); // G3: first-come-first-served, drawn.

        $scopes = array_column(
            $component->getAudiences(),
            'scope',
        );
        self::assertNotContains(
            RecipientScope::ExpectedAdmitted,
            $scopes,
        );
        self::assertNotContains(
            RecipientScope::ExpectedWaitlisted,
            $scopes,
        );
        // The settled scopes take over once the draw has locked the list.
        self::assertContains(
            RecipientScope::Admitted,
            $scopes,
        );
        self::assertContains(
            RecipientScope::Waitlisted,
            $scopes,
        );
    }

    public function testTheExpectedScopesAreNotOfferedForAConditionalDraw(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(33); // F2: a not-yet-drawn conditional draw has no deterministic expectation.

        $scopes = array_column(
            $component->getAudiences(),
            'scope',
        );
        self::assertNotContains(
            RecipientScope::ExpectedAdmitted,
            $scopes,
        );
        self::assertNotContains(
            RecipientScope::ExpectedWaitlisted,
            $scopes,
        );
    }

    public function testSendingToTheExpectedScopesReachesWhatTheDrawWillDecide(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(24); // C2: capacity four, nine sign-ups, ranked by cohort.

        $component->setScope('expected-admitted');
        $component->emailSubject = 'You have a place';
        $component->emailBody = 'When the list is drawn, you are in.';
        $component->sendEmail(22);

        $component->setScope('expected-waitlisted');
        $component->emailSubject = 'You are waiting';
        $component->emailBody = 'When the list is drawn, you wait.';
        $component->sendEmail(22);

        // The draw is first-come-first-served, so it is deterministic: the admitted and waiting sets it produces
        // are exactly what the expectation computed before it ran.
        $component->drawFirstCome(22);

        $list = $this->entityManager->getRepository(SignupList::class)->find(22);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );
        self::assertNotNull($list->drawnAt);

        $drawn = [];
        $waiting = [];
        foreach ($list->getSignUps() as $signup) {
            $email = $signup->getEmail();
            if (null === $email) {
                continue;
            }

            if ($signup->drawn) {
                $drawn[] = $email;
            } else {
                $waiting[] = $email;
            }
        }

        $sent = $this->bulkMessages();
        self::assertCount(
            2,
            $sent,
        );
        self::assertEqualsCanonicalizing(
            $drawn,
            array_column(
                $sent[0]->getRecipients(),
                'email',
            ),
        );
        self::assertEqualsCanonicalizing(
            $waiting,
            array_column(
                $sent[1]->getRecipients(),
                'email',
            ),
        );
        self::assertCount(
            4,
            $drawn,
        );
        self::assertCount(
            5,
            $waiting,
        );
    }

    public function testTheExpectedScopesOfAListWithReservedPlacesReachWhatTheDrawWillDecide(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(21); // B2: capacity eight, thirteen sign-ups, places held back.

        $component->setScope('expected-admitted');
        $component->emailSubject = 'You have a place';
        $component->emailBody = 'When the list is drawn, you are in.';
        $component->sendEmail(19);

        $component->setScope('expected-waitlisted');
        $component->emailSubject = 'You are waiting';
        $component->emailBody = 'When the list is drawn, you wait.';
        $component->sendEmail(19);

        $component->drawFirstCome(19);

        $list = $this->entityManager->getRepository(SignupList::class)->find(19);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );

        $drawn = [];
        $waiting = [];
        foreach ($list->getSignUps() as $signup) {
            $email = $signup->getEmail();
            if (null === $email) {
                continue;
            }

            if ($signup->drawn) {
                $drawn[] = $email;
            } else {
                $waiting[] = $email;
            }
        }

        $sent = $this->bulkMessages();
        self::assertCount(
            2,
            $sent,
        );
        self::assertEqualsCanonicalizing(
            $drawn,
            array_column(
                $sent[0]->getRecipients(),
                'email',
            ),
        );
        self::assertEqualsCanonicalizing(
            $waiting,
            array_column(
                $sent[1]->getRecipients(),
                'email',
            ),
        );
        self::assertCount(
            8,
            $drawn,
        );
        self::assertCount(
            5,
            $waiting,
        );
    }

    public function testTheExpectedScopesAreRefusedOnceTheDrawIsLocked(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(37); // G3: drawn, so the expectation is no longer offered.
        $component->setScope('expected-admitted');
        $component->emailSubject = 'You have a place';
        $component->emailBody = 'Too late: the list is already drawn.';

        $component->sendEmail(35);

        self::assertSame(
            [],
            $this->bulkMessages(),
        );
        self::assertSame(
            AlertTypes::Warning->value,
            $component->feedbackType,
        );
    }

    public function testTheExpectedScopesAreRefusedInPeopleMode(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->overviewFor(36);
        $component->setMode(SignupOverview::MODE_PEOPLE);
        $component->setScope('expected-admitted');
        $component->emailSubject = 'You have a place';
        $component->emailBody = 'Admission is per sign-up list, so it cannot be stated across them.';

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

    public function testTheRowOffersTheExpectedEmailShortcutsInsteadOfTheSettledOnes(): void
    {
        $this->authenticate(['ROLE_BOARD']);

        // A2 is not yet drawn, so the row's email shortcuts are the expected pair rather than the settled one.
        $html = $this->render(18);
        self::assertStringContainsString(
            'Email the expected admittees',
            $html,
        );
        self::assertStringContainsString(
            'Email the expected waiting list',
            $html,
        );
        self::assertStringNotContainsString(
            'Email admittees',
            $html,
        );

        // G3 is drawn, so the settled pair shows and the expected ones are not offered alongside it.
        $html = $this->render(37);
        self::assertStringContainsString(
            'Email admittees',
            $html,
        );
        self::assertStringContainsString(
            'Email the waiting list',
            $html,
        );
        self::assertStringNotContainsString(
            'Email the expected admittees',
            $html,
        );
    }

    /**
     * @param string[] $roles
     */
    private function authenticate(
        array $roles,
        int $lidnr = 8025,
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

        $activity = $this->entityManager->getRepository(Activity::class)->find($activityId);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );
        $component->activity = $activity;

        return $component;
    }

    /**
     * @return int[]
     */
    private function signupIds(int $listId): array
    {
        $list = $this->entityManager->getRepository(SignupList::class)->find($listId);
        self::assertInstanceOf(
            SignupList::class,
            $list,
        );

        $ids = [];
        foreach ($list->getSignUps() as $signup) {
            if (null === $signup->id) {
                continue;
            }

            $ids[] = $signup->id;
        }

        return $ids;
    }

    /**
     * The component as the page renders it, so the template runs against the real read-models without the HTTP layer
     * the class comment rules out.
     *
     * @param array<string, mixed> $props
     */
    private function render(
        int $activityId,
        array $props = [],
    ): string {
        $activity = $this->entityManager->getRepository(Activity::class)->find($activityId);
        self::assertInstanceOf(
            Activity::class,
            $activity,
        );

        // The dates are written in the request's locale, so there has to be a request.
        self::getContainer()->get('request_stack')->push(new Request());

        // Through a template rather than the renderer, which requires a Twig template on the stack for the live id.
        return self::getContainer()->get(Environment::class)
            ->createTemplate('{{ component(\'Activity:Admin:SignupOverview\', props) }}')
            ->render(['props' => ['activity' => $activity] + $props]);
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
