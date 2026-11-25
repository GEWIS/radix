<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Activity;

use App\Controller\Activity\AdminApprovalController;
use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityLocalisedText;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\ActivityRevisionEdit;
use App\Entity\Activity\SignupList;
use App\Entity\Application\EditLock;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\RevisionStatus;
use App\Entity\User\User;
use App\Repository\Activity\ActivityRevisionCommentRepository;
use App\Repository\Application\EditLockRepository;
use App\Security\User\SudoMode;
use App\Service\Activity\ActivityRevisionCloner;
use App\Service\Application\EditLockService;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use SortDirection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Workflow\Registry;

use function count;
use function str_contains;

/**
 * The review controller is thin glue over already-tested pieces (the workflow, {@see RevisionDiscarder},
 * {@see EditLockService}, {@see \App\Form\Application\ReviewDecisionType}); these pin its own discard action, the
 * recovery path for a draft whose submit the workflow withholds.
 *
 * The actions are invoked directly with the current user set on the token storage, rather than over HTTP: the app's
 * session guard force-logs-out any session not backed by a managed-session row, so a synthetic browser session never
 * survives a request. The HTTP boundary itself (routing, CSRF, the decision form's button-to-transition mapping) is
 * therefore left to the browser-level suite, where a real login establishes a real managed session.
 */
final class AdminApprovalControllerTest extends DatabaseTestCase
{
    public function testDiscardRevertsAReEditToLiveAndPurgesTheEditLock(): void
    {
        $activity = $this->anApprovedActivityWithoutSignupLists();
        $live = $activity->getLiveRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $live,
        );

        // A draft re-edit of the approved activity, with an edit lock held on the aggregate.
        $draft = $this->cloner()->cloneAsDraft($live);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );
        $this->entityManager->persist($draft);
        $this->entityManager->flush();
        $draftId = (int) $draft->id;
        $this->editLockService()->acquire(
            $activity,
            $this->user(8025),
        );

        $this->authenticate(['ROLE_BOARD']);
        $this->pushRequestWithSession();

        $response = $this->controller()->discard($draft);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        // The activity is back on its live revision, the draft is gone ...
        self::assertSame(
            $live,
            $activity->getCurrentRevision(),
        );
        self::assertNull(
            $this->entityManager->getRepository(ActivityRevision::class)->find($draftId),
        );
        // ... and the now-meaningless edit lock was purged with it.
        self::assertNull(
            $this->editLocks()->findOneByResource(
                $activity->getResourceId(),
                (int) $activity->id,
            ),
        );
    }

    public function testDiscardIsRefusedWhenThereIsNoLiveVersionToRevertTo(): void
    {
        // A brand-new (never-approved) draft has nothing to revert to; discarding it would delete the whole activity,
        // which is deliberately left to the stale-revision cleanup. The controller refuses and keeps the draft.
        $draft = $this->aNeverApprovedDraft();
        $draftId = (int) $draft->id;

        $this->authenticate(['ROLE_BOARD']);
        $session = $this->pushRequestWithSession();

        $response = $this->controller()->discard($draft);

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertNotNull(
            $this->entityManager->getRepository(ActivityRevision::class)->find($draftId),
        );
        self::assertNotEmpty(self::flashBag($session)->peek(AlertTypes::Warning->value));
    }

    public function testDiscardIsDeniedForANonOwnerNonBoardMember(): void
    {
        $draft = $this->aNeverApprovedDraft();

        // 8027 is in KEUR, not the organising organ (GETÉST), and neither created the activity nor reviews it, so
        // editing it away is denied.
        $this->authenticate(
            ['ROLE_ACTIVE_MEMBER'],
            8027,
        );
        $this->pushRequestWithSession();

        $this->expectException(AccessDeniedException::class);
        $this->controller()->discard($draft);
    }

    public function testDiscardTakesTheEditTrailWithIt(): void
    {
        $activity = $this->anApprovedActivityWithoutSignupLists();
        $live = $activity->getLiveRevision();
        self::assertInstanceOf(
            ActivityRevision::class,
            $live,
        );

        $draft = $this->cloner()->cloneAsDraft($live);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );
        $this->entityManager->persist($draft);

        $draft->setLastEditedBy($this->user(8025));
        $list = new SignupList();
        $list->name = new ActivityLocalisedText(
            'Deelnemers',
            'Participants',
        );
        $list->openDate = new DateTimeImmutable('2030-01-01 12:00');
        $list->closeDate = new DateTimeImmutable('2030-02-01 12:00');
        $draft->addSignupList($list);

        $edit = new ActivityRevisionEdit();
        $edit->revision = $draft;
        $edit->setEditor($this->user(8025));
        $edit->editedAt = new DateTimeImmutable();
        $edit->changedFields = ['name'];
        $this->entityManager->persist($edit);
        $this->entityManager->flush();

        $draftId = (int) $draft->id;
        $this->entityManager->clear();
        $draft = $this->entityManager->getRepository(ActivityRevision::class)->find($draftId);
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
        );

        $this->authenticate(['ROLE_BOARD']);
        $this->pushRequestWithSession();

        $this->controller()->discard($draft);

        self::assertNull(
            $this->entityManager->getRepository(ActivityRevision::class)->find($draftId),
        );
        self::assertSame(
            [],
            $this->entityManager->createQueryBuilder()
                ->select('e')
                ->from(
                    ActivityRevisionEdit::class,
                    'e',
                )
                ->where('IDENTITY(e.revision) = :revision')
                ->setParameter(
                    'revision',
                    $draftId,
                )
                ->getQuery()
                ->getResult(),
        );
    }

    public function testTheReviewScreenSaysWhichListWasLeftUnfinished(): void
    {
        $draft = $this->aNeverApprovedDraft();
        $list = new SignupList();
        $list->name = new ActivityLocalisedText();
        $draft->addSignupList($list);

        $this->authenticateAsBoardWithSudo();

        $html = (string) $this->controller()->review($draft)->getContent();

        self::assertStringContainsString(
            'Sign-up list 1',
            $html,
        );
        self::assertStringContainsString(
            'cannot be submitted or approved yet',
            $html,
        );
    }

    public function testTheReviewScreenReadsNoLocalisedTextOnItsOwn(): void
    {
        $revision = $this->aRevisionWithSignupLists();

        $this->authenticateAsBoardWithSudo();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $this->controller()->review($revision);

        $texts = 0;
        foreach ($holder->getData() as $queries) {
            foreach ($queries as $query) {
                if (
                    !str_contains(
                        $query['sql'],
                        'FROM ActivityLocalisedText',
                    )
                ) {
                    continue;
                }

                ++$texts;
            }
        }

        self::assertSame(
            0,
            $texts,
        );
    }

    public function testTheApprovalQueueRenders(): void
    {
        $this->authenticate(['ROLE_BOARD']);
        $this->pushRequestWithSession();

        $response = $this->controller()->index();

        self::assertSame(
            200,
            $response->getStatusCode(),
        );
    }

    public function testStartingAReviewKeepsTheBoardOnTheScreen(): void
    {
        $revision = $this->aRevisionInStatus(RevisionStatus::Submitted);
        $request = $this->boardDecisionRequest([
            'start_review' => '',
        ]);

        $response = $this->controller()->decide(
            $request,
            $this->user(8025),
            $revision,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            RevisionStatus::InReview,
            $revision->getStatus(),
        );
        self::assertStringContainsString(
            '/admin/activities/approvals/' . $revision->id,
            $response->getTargetUrl(),
        );
        self::assertContains(
            'Review started.',
            self::flashBag($request)->get(AlertTypes::Success->value),
        );
    }

    public function testApprovingMakesTheRevisionLiveAndReturnsToTheQueue(): void
    {
        $revision = $this->aRevisionInStatus(RevisionStatus::InReview);
        $request = $this->boardDecisionRequest([
            'approve' => '',
        ]);

        $response = $this->controller()->decide(
            $request,
            $this->user(8025),
            $revision,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            RevisionStatus::Approved,
            $revision->getStatus(),
        );
        self::assertSame(
            $revision,
            $revision->activity->getLiveRevision(),
        );
        self::assertStringEndsWith(
            '/admin/activities/approvals',
            $response->getTargetUrl(),
        );
        self::assertContains(
            'The activity revision was updated.',
            self::flashBag($request)->get(AlertTypes::Success->value),
        );
    }

    public function testRejectingWithFeedbackTurnsTheRevisionDown(): void
    {
        $revision = $this->aRevisionInStatus(RevisionStatus::Submitted);

        // A decision is made from the in-review screen, which is where the board claims a submission first. The claim
        // is a guarded transition, so the board is signed in before it is applied.
        $request = $this->boardDecisionRequest([
            'reject' => '',
            'message' => 'This does not fit the association calendar.',
        ]);
        self::getContainer()->get(Registry::class)->get(
            $revision,
            'revision',
        )->apply(
            $revision,
            'start_review',
        );
        $this->entityManager->flush();

        $response = $this->controller()->decide(
            $request,
            $this->user(8025),
            $revision,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            RevisionStatus::Rejected,
            $revision->getStatus(),
        );
    }

    public function testAnAuthorSubmittingADraftGoesBackToTheirOverview(): void
    {
        // The seeded draft that continues a "changes requested" review, so submitting it also includes the response
        // the resubmission form requires.
        $revision = $this->aBeerTastingDraft();
        $author = $revision->getAuthor();
        self::assertNotNull($author);
        $author = $this->entityManager->getRepository(User::class)->find(
            $author->lidnr,
        );
        self::assertInstanceOf(
            User::class,
            $author,
        );

        $request = $this->decisionRequest(
            $author,
            [
                'submit' => '',
                'message' => 'The price is on the page and the room is booked.',
            ],
            false,
        );

        $response = $this->controller()->decide(
            $request,
            $author,
            $revision,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertSame(
            RevisionStatus::Submitted,
            $revision->getStatus(),
        );
        self::assertStringEndsWith(
            '/admin/activities',
            $response->getTargetUrl(),
        );
        self::assertContains(
            'Activity submitted for review.',
            self::flashBag($request)->get(AlertTypes::Success->value),
        );
    }

    public function testACommentIsAddedAndTheReaderStaysOnTheScreen(): void
    {
        $revision = $this->aRevisionInStatus(RevisionStatus::InReview);

        $before = count(self::getContainer()->get(ActivityRevisionCommentRepository::class)
            ->findThreadForActivity($revision->activity));

        $request = $this->boardDecisionRequest([]);
        $request->request->set(
            'message',
            'The venue is confirmed.',
        );

        $response = $this->controller()->comment(
            $request,
            $this->user(8025),
            $revision,
        );

        self::assertInstanceOf(
            RedirectResponse::class,
            $response,
        );
        self::assertStringContainsString(
            '/admin/activities/approvals/' . $revision->id,
            $response->getTargetUrl(),
        );
        self::assertCount(
            $before + 1,
            self::getContainer()->get(ActivityRevisionCommentRepository::class)
                ->findThreadForActivity($revision->activity),
        );
    }

    /**
     * @param array<string, string> $decision
     */
    private function boardDecisionRequest(array $decision): Request
    {
        return $this->decisionRequest(
            $this->user(8025),
            $decision,
            true,
        );
    }

    /**
     * @param array<string, string> $decision
     */
    private function decisionRequest(
        User $user,
        array $decision,
        bool $sudo,
    ): Request {
        $decision['_csrf_token'] = 'csrf-token';

        $request = new Request(
            request: ['review_decision' => $decision],
            server: ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $request->setMethod('POST');

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));

        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );
        $request->setSession($session);
        $request->setLocale('en');
        $request->cookies->set(
            $session->getName(),
            'test',
        );
        self::getContainer()->get('request_stack')->push($request);

        if ($sudo) {
            self::getContainer()->get(SudoMode::class)->grant();
        }

        return $request;
    }

    /**
     * The seeded second revision of "Beer Tasting": a draft that responds to a "changes requested" review.
     */
    private function aBeerTastingDraft(): ActivityRevision
    {
        foreach ($this->entityManager->getRepository(ActivityRevision::class)->findAll() as $revision) {
            if (
                RevisionStatus::Draft === $revision->getStatus()
                && 'Beer Tasting' === $revision->name->getValueEN()
                && null !== $revision->getPreviousRevision()
            ) {
                return $revision;
            }
        }

        self::fail('The seed is expected to contain the resubmitted Beer Tasting draft.');
    }

    private function aRevisionInStatus(RevisionStatus $status): ActivityRevision
    {
        $revision = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                ActivityRevision::class,
                'r',
            )
            ->where('r.status = :status')
            ->setParameter(
                'status',
                $status->value,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
            'The seed is expected to contain a revision in status ' . $status->value . '.',
        );

        return $revision;
    }

    private function authenticateAsBoardWithSudo(): void
    {
        $this->authenticate(['ROLE_BOARD']);

        $session = $this->pushRequestWithSession();
        $request = self::getContainer()->get('request_stack')->getCurrentRequest();
        self::assertInstanceOf(
            Request::class,
            $request,
        );
        // A sudo grant is only read from a session the request already has, so the cookie has to be there.
        $request->cookies->set(
            $session->getName(),
            'test',
        );

        self::getContainer()->get(SudoMode::class)->grant();
    }

    private function controller(): AdminApprovalController
    {
        return self::getContainer()->get(AdminApprovalController::class);
    }

    private function cloner(): ActivityRevisionCloner
    {
        return self::getContainer()->get(ActivityRevisionCloner::class);
    }

    private function editLockService(): EditLockService
    {
        return self::getContainer()->get(EditLockService::class);
    }

    private function editLocks(): EditLockRepository
    {
        return $this->entityManager->getRepository(EditLock::class);
    }

    /**
     * @param string[] $roles
     */
    private function authenticate(
        array $roles,
        int $lidnr = 8025,
    ): void {
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $this->user($lidnr),
            'main',
            $roles,
        ));
    }

    /**
     * A request with a session must be on the stack for the controller's flash messages to land somewhere.
     */
    private function pushRequestWithSession(): FlashBagAwareSessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        self::assertInstanceOf(
            FlashBagAwareSessionInterface::class,
            $session,
        );

        $request = new Request();
        $request->setSession($session);
        self::getContainer()->get('request_stack')->push($request);

        return $session;
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

    private function anApprovedActivityWithoutSignupLists(): Activity
    {
        $activity = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(
                Activity::class,
                'a',
            )
            ->join(
                'a.liveRevision',
                'lr',
            )
            ->where('a.currentRevision = a.liveRevision')
            ->andWhere('SIZE(lr.signupLists) = 0')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            Activity::class,
            $activity,
            'The seed is expected to contain an approved activity without sign-up lists.',
        );

        return $activity;
    }

    private function aRevisionWithSignupLists(): ActivityRevision
    {
        $revision = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                ActivityRevision::class,
                'r',
            )
            ->where('SIZE(r.signupLists) > 0')
            ->orderBy(
                'SIZE(r.signupLists)',
                SortDirection::Descending,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            ActivityRevision::class,
            $revision,
            'The seed is expected to contain a revision with sign-up lists.',
        );

        return $revision;
    }

    private function aNeverApprovedDraft(): ActivityRevision
    {
        $draft = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(
                ActivityRevision::class,
                'r',
            )
            ->join(
                'r.activity',
                'a',
            )
            ->where('r.status = :draft')
            ->andWhere('a.liveRevision IS NULL')
            ->andWhere('a.currentRevision = r')
            ->setParameter(
                'draft',
                RevisionStatus::Draft->value,
            )
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(
            ActivityRevision::class,
            $draft,
            'The seed is expected to contain a never-approved draft activity.',
        );

        return $draft;
    }
}
