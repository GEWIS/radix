<?php

declare(strict_types=1);

namespace App\Controller\Activity;

use App\Controller\Application\AbstractRevisionReviewController;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Application\RevisionInterface;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Repository\Activity\ActivityRevisionCommentRepository;
use App\Service\Activity\SignupListMigrator;
use App\Util\Activity\PastActivityRule;
use App\Util\Activity\SignupListRule;
use App\ViewModel\Application\RevisionActions;
use Override;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

/**
 * The shared review surface for activities:
 *  - a per-revision screen with a diff against the previous revision
 *  - the discussion thread
 *  - the workflow transitions
 *
 * It is used both by the board (approve/reject/request changes/...) and by the activity's own organisers (submit for
 * review, commenting). The available transitions are whatever the `revision` workflow guards allow the current user, so
 * each role only ever sees its own actions. The submission queue ({@see self::index()}) remains board-only.
 */
#[Route(
    path: '/admin/activities/approvals',
    name: 'admin/activities/approvals/',
)]
class AdminApprovalController extends AbstractRevisionReviewController
{
    public function __construct(
        private readonly ActivityRevisionCommentRepository $commentRepository,
        private readonly SignupListMigrator $signupListMigrator,
    ) {
    }

    #[Route(
        path: '',
        name: 'index',
    )]
    #[IsGranted(UserRoles::Board->value)]
    public function index(): Response
    {
        // The queue itself is `Activity:Admin:ApprovalOverview`, which pages over the submissions on its own.
        return $this->render('activity/admin/approvals/index.html.twig');
    }

    #[Route(
        path: '/{revision}',
        name: 'review',
        requirements: ['revision' => '\d+'],
    )]
    public function review(ActivityRevision $revision): Response
    {
        $this->assertMayReview($revision);

        return $this->renderReview($revision);
    }

    #[Route(
        path: '/{revision}/decide',
        name: 'decide',
        requirements: ['revision' => '\d+'],
        methods: ['POST'],
    )]
    public function decide(
        Request $request,
        #[CurrentUser]
        User $user,
        ActivityRevision $revision,
    ): Response {
        return $this->handleDecision(
            $request,
            $revision,
            $user,
        );
    }

    #[Route(
        path: '/{revision}/comment',
        name: 'comment',
        requirements: ['revision' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_review_comment-" ~ args["revision"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function comment(
        Request $request,
        #[CurrentUser]
        User $user,
        ActivityRevision $revision,
    ): Response {
        $this->handleCommentPost(
            $request,
            $revision,
            $user,
        );

        return $this->reviewResponse($revision);
    }

    /**
     * Throw away a draft re-edit and point the activity back at its live (approved) version. This is the recovery for
     * a draft whose `submit` the workflow withholds because it restructured a list the live revision now has sign-ups
     * on (see {@see \App\EventListener\Application\SignupMigrationGuardListener}): the diverged structure cannot be
     * fixed in place, so the organiser discards it and revises the live version afresh.
     */
    #[Route(
        path: '/{revision}/discard',
        name: 'discard',
        requirements: ['revision' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_discard-" ~ args["revision"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function discard(ActivityRevision $revision): Response
    {
        return $this->discardDraft(
            $revision,
            'admin/activities/index',
        );
    }

    #[Override]
    protected function reviewTemplate(): string
    {
        return 'activity/admin/approvals/review.html.twig';
    }

    /**
     * trans() is called per arm (not around the match) so each literal stays statically extractable.
     */
    #[Override]
    protected function decisionFlash(string $transition): string
    {
        return match ($transition) {
            'submit' => $this->translator->trans('Activity submitted for review.'),
            'start_review' => $this->translator->trans('Review started.'),
            default => $this->translator->trans('The activity revision was updated.'),
        };
    }

    /**
     * Authors return to their overview; starting a review stays on the (now in-review) screen so the board can decide
     * straight away; every other decision returns to the queue.
     */
    #[Override]
    protected function decisionResponse(
        RevisionInterface $revision,
        string $transition,
    ): Response {
        return match ($transition) {
            'submit' => $this->redirectToRoute('admin/activities/index'),
            'start_review' => $this->reviewResponse($revision),
            default => $this->redirectToRoute('admin/activities/approvals/index'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function reviewContext(
        RevisionInterface $revision,
        RevisionActions $actions,
    ): array {
        assert($revision instanceof ActivityRevision);

        // When this revision restructured/removed a sign-up list that the live revision has sign-ups on, the workflow
        // withholds approve/submit (SignupMigrationGuardListener); explain why on the screen.
        $live = $revision->getActivity()->getLiveRevision();
        $migrationBlocked = null !== $live
            && $live !== $revision
            && !$this->signupListMigrator->isMigratable(
                $live,
                $revision,
            );

        // When the activity can no longer be published, the workflow withholds submit/approve
        // (PastActivityGuardListener); explain why on the screen. Two cases: an established activity whose live
        // schedule has *ended*, and a brand-new activity (no live revision) whose own *start* has already passed.
        // The latter is recoverable by re-dating the draft, so it gets a different banner.
        $liveEnded = PastActivityRule::liveEnded(
            $live,
            $revision,
        );
        $debutMissed = PastActivityRule::debutMissed(
            $live,
            $revision,
        );

        $unfinished = SignupListRule::firstUnfinished($revision);

        return [
            'activity' => $revision->getActivity(),
            'comments' => $this->commentRepository->findThreadForActivity($revision->getActivity()),
            'migrationBlocked' => $migrationBlocked,
            'activityPassed' => $liveEnded || $debutMissed,
            'debutMissed' => $debutMissed,
            'unfinishedSignupList' => null === $unfinished ? null : [
                'name' => SignupListRule::label(
                    $unfinished['list'],
                    $unfinished['position'],
                    $this->translator,
                ),
                'part' => $unfinished['section']->trans($this->translator),
            ],
        ];
    }

    #[Override]
    protected function reviewResponse(RevisionInterface $revision): Response
    {
        return $this->redirectToRoute(
            'admin/activities/approvals/review',
            ['revision' => $revision->getId()],
        );
    }
}
