<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Activity\Admin;

use App\Entity\Activity\Activity;
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
 * not-yet-drawn limited list (#11, capacity 2, four sign-ups) ready for a draw.
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
        // All four subscribers carry an email, so the default "everyone" scope reaches all of them.
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
            if (!$signup->isDrawn()) {
                continue;
            }

            ++$drawn;
        }

        self::assertSame(
            2,
            $drawn,
        );
        self::assertNotNull($list->getDrawnAt());
        self::assertNotNull($list->getDrawnBy());
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
     * The component as the page draws it, so the template runs against the real read-models without the HTTP layer
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

        // Through a template rather than the renderer, which wants a Twig template on the stack for the live id.
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
