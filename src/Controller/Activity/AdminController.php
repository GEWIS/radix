<?php

declare(strict_types=1);

namespace App\Controller\Activity;

use App\Controller\Application\HandlesFormFlowTrait;
use App\Controller\Application\HoldsEditLockTrait;
use App\Entity\Activity\Activity;
use App\Entity\Activity\ActivityRevision;
use App\Entity\Activity\SignupList;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\ReviseRefusal;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Form\Activity\ActivityFlow\ActivityFlowType;
use App\Form\Activity\SignupType;
use App\Repository\Activity\ActivityRepository;
use App\Repository\Activity\ActivityRevisionCommentRepository;
use App\Repository\Activity\ExternalSignupRepository;
use App\Security\Application\RevisionVoter;
use App\Service\Activity\ActivityAdminService;
use App\Service\Activity\ActivityDraftFactory;
use App\Service\Activity\ActivityFormMapper;
use App\Service\Activity\SignupManager;
use App\Service\Application\RevisionReviewService;
use App\Service\Application\RevisionReviser;
use App\Util\Activity\PastActivityRule;
use App\Util\Activity\SignupAdminWindow;
use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;
use function is_int;
use function strval;

#[Route(
    path: '/admin/activities',
    name: 'admin/activities/',
)]
class AdminController extends AbstractController
{
    use HandlesFormFlowTrait;
    use HoldsEditLockTrait;

    public function __construct(
        private readonly ActivityAdminService $activityAdminService,
        private readonly ActivityDraftFactory $activityDraftFactory,
        private readonly ActivityRepository $activityRepository,
        private readonly ActivityRevisionCommentRepository $commentRepository,
        private readonly RevisionReviewService $revisionReviewService,
        private readonly RevisionReviser $reviser,
        private readonly TranslatorInterface $translator,
        private readonly ActivityFormMapper $activityFormMapper,
    ) {
    }

    #[Route(
        path: '',
        name: 'index',
    )]
    public function index(): Response
    {
        // The two tables (pending + approved), scoping and the board "show all" toggle live in the
        // Activity:Admin:ActivityOverview live component embedded by this template.
        return $this->render('activity/admin/index.html.twig');
    }

    #[Route(
        path: '/create',
        name: 'create',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function create(
        Request $request,
        #[CurrentUser]
        User $user,
    ): Response {
        $activity = $this->activityDraftFactory->newActivity($user->getMember());

        $revision = $activity->getCurrentRevision();
        assert($revision instanceof ActivityRevision);

        $companyEditable = $this->isGranted(UserRoles::CompanyAdmin->value);

        $data = new ActivityData();
        $data->companyEditable = $companyEditable;

        $run = $this->flowRun($request);

        if ($run instanceof RedirectResponse) {
            return $run;
        }

        $flow = $this->createFlow(
            ActivityFlowType::class,
            $data,
            [
                'flow_key' => $run,
                'revision' => $revision,
                'company_editable' => $companyEditable,
            ],
        );
        $flow->handleRequest($request);

        if (!$flow->isFinished()) {
            $this->flashRejectedStep(
                $flow,
                $this->translator,
            );

            $form = $flow->getStepForm();
            $this->restoreSignupLists($form);

            return $this->render(
                'activity/admin/create.html.twig',
                ['form' => $form],
            );
        }

        $collected = $flow->getData();
        assert($collected instanceof ActivityData);
        $this->activityFormMapper->apply(
            $collected,
            $revision,
        );

        $this->activityAdminService->create($activity);
        $flow->reset();

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('Activity saved as a draft. Submit it for review when you are ready.'),
        );

        return $this->redirectToRoute('admin/activities/index');
    }

    #[Route(
        path: '/{activity}/edit',
        name: 'edit',
        requirements: ['activity' => '\d+'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        #[CurrentUser]
        User $user,
        Activity $activity,
    ): Response {
        // Owners, organ members and reviewers may revise the activity.
        $this->denyAccessUnlessGranted(
            RevisionVoter::SUBMIT,
            $activity,
        );

        $this->activityRepository->warmForEditing($activity);

        $current = $activity->getCurrentRevision();
        if (null === $current) {
            throw $this->createNotFoundException();
        }

        $refusal = $current->getStatus()->reviseRefusal();

        // An activity that has gone live and already taken place is immutable: no further revisions, whether the
        // current revision is the live one or an in-flight draft of it. "Took place" is a property of the real
        // (live) schedule, so it is read from the live revision; a brand-new activity that was never approved (no
        // live revision) is still editable even if its draft date has slipped into the past.
        $live = $activity->getLiveRevision();
        if (
            null !== $live
            && PastActivityRule::ended($live)
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity has already taken place and can no longer be revised.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        // Locked while it is with the board, and final once the board has closed it. Both are decided before any edit
        // lock is even considered.
        if (
            ReviseRefusal::UnderReview === $refusal
            || ReviseRefusal::Closed === $refusal
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                ReviseRefusal::UnderReview === $refusal
                    ? $this->translator->trans('This activity is being reviewed and cannot be edited right now.')
                    : $this->translator->trans('This activity was closed by the board and can no longer be revised.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        // A cancelled activity is a finished tombstone: it stays visible but is not revised. (An unpublished activity,
        // by contrast, is only temporarily hidden and stays fully editable so it can be fixed up before re-publishing.)
        if ($activity->isCancelled()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity has been cancelled. Un-cancel it first to revise it.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        // Acquire the exclusive edit lock before spawning/binding: this is also what prevents two people both spawning
        //a competing draft of the same live activity. A reviewer may force-take an alive lock (?take=1).
        $forceTake = $request->query->getBoolean('take')
            && $this->isGranted(UserRoles::Board->value);
        $lock = $this->editLockService->acquire(
            $activity,
            $user,
            $forceTake,
        );
        if (null === $lock) {
            return $this->renderLocked(
                $activity,
                $user,
            );
        }

        if (ReviseRefusal::AlreadyADraft === $refusal) {
            // A draft is edited in place.
            $revision = $current;
        } else {
            // An approved/rejected activity is revised by spawning a new draft linked to the current revision; the
            // editing member becomes its author.
            $revision = $this->reviser->spawnDraft(
                $current,
                $user,
            );
        }

        // The registry is typed to the shared RevisionInterface; for an activity it always yields an ActivityRevision.
        assert($revision instanceof ActivityRevision);

        // For a spawned draft the cloner has already pointed the activity's current revision at it; for an in-place
        // draft it was already current.
        $companyEditable = $this->isGranted(UserRoles::CompanyAdmin->value);
        $scheduleLocked = $this->scheduleIsLocked($revision);

        $data = ActivityData::fromRevision(
            $revision,
            $scheduleLocked,
        );
        $data->companyEditable = $companyEditable;

        $run = $this->flowRun($request);

        if ($run instanceof RedirectResponse) {
            return $run;
        }

        $flow = $this->createFlow(
            ActivityFlowType::class,
            $data,
            [
                'flow_key' => $run,
                'revision' => $revision,
                'schedule_locked' => $scheduleLocked,
                'company_editable' => $companyEditable,
                'bound_organ_id' => $revision->getOrgan()?->getId(),
                'finish_label' => $this->translator->trans('Save changes'),
            ],
        );
        $flow->handleRequest($request);

        if (!$flow->isFinished()) {
            if (
                !$flow->isSubmitted()
                && null !== $revision->getId()
            ) {
                // Remember, server-side, the version this edit started from, so the optimistic-lock check on save
                // cannot be bypassed by tampering a client-submitted field.
                $request->getSession()->set(
                    $this->editVersionKey($activity),
                    $revision->getVersion(),
                );
            }

            $this->flashRejectedStep(
                $flow,
                $this->translator,
            );

            $form = $flow->getStepForm();
            $this->restoreSignupLists($form);

            return $this->render(
                'activity/admin/edit.html.twig',
                [
                    'form' => $form,
                    'activity' => $activity,
                    'comments' => $this->commentRepository->findThreadForActivity($activity),
                ],
            );
        }

        $collected = $flow->getData();
        assert($collected instanceof ActivityData);
        $this->activityFormMapper->apply(
            $collected,
            $revision,
        );

        // Refuse the save only if the lock was force-taken by SOMEONE ELSE (a reviewer) while this form was open. We
        // use the read-only blockingLock() rather than ping(): ping() flushes, which would commit the bound form
        // changes before the optimistic-version check below and before lastEditedBy is stamped (breaking both), and a
        // lock we self-released on navigation (a page-unload beacon racing the submit) must not count as "taken over".
        if (
            null !== $this->editLockService->blockingLock(
                $activity,
                $user,
            )
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity was taken over by a reviewer, so your changes were not saved.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        // Optimistic-locking backstop for an in-place draft edit (a spawned draft is brand-new, nothing to race). The
        // base version is read from the server-side session (stamped when the form was opened), never from the request,
        // so it cannot be forged to slip a stale edit past the check.
        if (null !== $revision->getId()) {
            $baseVersion = $request->getSession()->get($this->editVersionKey($activity));
            if (!is_int($baseVersion)) {
                return $this->flashAndBackToEdit(
                    $activity,
                    $this->translator->trans('Your edit session expired; reopen the activity and try again.'),
                );
            }

            try {
                $this->activityAdminService->claimVersion(
                    $revision,
                    $baseVersion,
                );
            } catch (OptimisticLockException) {
                return $this->flashAndBackToEdit(
                    $activity,
                    $this->translator->trans('This revision was changed elsewhere; reload the page and try again.'),
                );
            }
        }

        $this->activityAdminService->saveDraft(
            $activity,
            $revision,
            $user,
        );
        $request->getSession()->remove($this->editVersionKey($activity));
        $flow->reset();

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('Changes saved. Submit the revision for review when you are ready.'),
        );

        return $this->redirectToRoute('admin/activities/index');
    }

    /**
     * Fill the sign-up lists step back in with what it last held. The lists are edited on the revision, which is built
     * afresh on every request and so arrives empty; only what the step was filled in with travels along in the flow.
     * Handing that back to the step is what turns it into lists again, so a step that is returned to is the step that
     * was left.
     *
     * @param FormInterface<mixed> $form
     */
    private function restoreSignupLists(FormInterface $form): void
    {
        if (!$form->has(ActivityData::STEP_SIGNUP_LISTS)) {
            return;
        }

        $step = $form->get(ActivityData::STEP_SIGNUP_LISTS);
        $data = $form->getData();

        // A step that was just handed in already holds what was typed into it, right down to what was rejected.
        if (
            $step->isSubmitted()
            || !$data instanceof ActivityData
            || null === $data->signupListsSubmission
        ) {
            return;
        }

        $step->submit([ActivityData::STEP_SIGNUP_LISTS => $data->signupListsSubmission]);
    }

    /**
     * Only a genuinely live, under-way activity has its start locked; a never-published draft stays editable.
     */
    private function scheduleIsLocked(ActivityRevision $revision): bool
    {
        $live = $revision->getActivity()->getLiveRevision();

        if (
            null === $live
            || $live === $revision
        ) {
            return false;
        }

        $beginTime = $live->getBeginTime();

        return null !== $beginTime
            && $beginTime <= new DateTime();
    }

    /**
     * The "someone else is editing this" screen: shows who holds the lock and, for reviewers, a take-over action.
     */
    private function renderLocked(
        Activity $activity,
        User $user,
    ): Response {
        return $this->render(
            'activity/admin/edit_locked.html.twig',
            [
                'activity' => $activity,
                'lock' => $this->editLockService->blockingLock(
                    $activity,
                    $user,
                ),
            ],
        );
    }

    #[Route(
        path: '/{activity}/edit/ping',
        name: 'edit_ping',
        requirements: ['activity' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_edit_lock-" ~ args["activity"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function editPing(
        #[CurrentUser]
        User $user,
        Activity $activity,
    ): JsonResponse {
        return $this->pingLock(
            $activity,
            $user,
        );
    }

    #[Route(
        path: '/{activity}/edit/release',
        name: 'edit_release',
        requirements: ['activity' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_edit_lock-" ~ args["activity"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function editRelease(
        #[CurrentUser]
        User $user,
        Activity $activity,
    ): JsonResponse {
        return $this->releaseLock(
            $activity,
            $user,
        );
    }

    #[Route(
        path: '/{activity}/reopen',
        name: 'reopen',
        requirements: ['activity' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_reopen-" ~ args["activity"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function reopen(
        #[CurrentUser]
        User $user,
        Activity $activity,
    ): Response {
        $this->denyAccessUnlessGranted(
            RevisionVoter::REOPEN,
            $activity,
        );

        $current = $activity->getCurrentRevision();
        if (null === $current) {
            throw $this->createNotFoundException();
        }

        // A live activity that has already taken place is immutable; reopening a rejected/closed revision into a new
        // draft must not become a back door around that.
        $live = $activity->getLiveRevision();
        if (
            null !== $live
            && PastActivityRule::ended($live)
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity has already taken place and can no longer be revised.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        // A revision the board closed is final: it cannot be revived into a new draft.
        if (ReviseRefusal::Closed === $current->getStatus()->reviseRefusal()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity was closed by the board and can no longer be revised.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        // A cancelled activity is a finished tombstone and is not revised; the board must un-cancel it first.
        if ($activity->isCancelled()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity has been cancelled. Un-cancel it first to revise it.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        // The cloner links the new draft and points the activity's current revision at it; the reopening member
        // becomes its author.
        $this->revisionReviewService->startDraft(
            $current,
            $user,
        );

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('A new draft was created. Edit it and submit again.'),
        );

        return $this->redirectToRoute(
            'admin/activities/edit',
            ['activity' => $activity->getId()],
        );
    }

    /**
     * Cancel an approved activity (board only). It stays publicly visible with a notice and a [CANCELLED] title marker,
     * but all sign-up interaction is frozen. Reversible via {@see self::uncancel()}.
     */
    #[Route(
        path: '/{activity}/cancel',
        name: 'cancel',
        requirements: ['activity' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted(UserRoles::Board->value)]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_cancel-" ~ args["activity"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function cancel(
        #[CurrentUser]
        User $user,
        Activity $activity,
    ): Response {
        if (
            null === $activity->getLiveRevision()
            || $activity->isCancelled()
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity cannot be cancelled.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        $this->activityAdminService->cancel(
            $activity,
            $user->getMember(),
        );

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The activity has been cancelled.'),
        );

        return $this->redirectToRoute('admin/activities/index');
    }

    /**
     * Un-cancel a previously cancelled activity (board only), restoring normal sign-up interaction.
     */
    #[Route(
        path: '/{activity}/uncancel',
        name: 'uncancel',
        requirements: ['activity' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted(UserRoles::Board->value)]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_uncancel-" ~ args["activity"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function uncancel(Activity $activity): Response
    {
        if (!$activity->isCancelled()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity is not cancelled.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        $this->activityAdminService->uncancel($activity);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The activity is no longer cancelled.'),
        );

        return $this->redirectToRoute('admin/activities/index');
    }

    /**
     * Unpublish an approved activity (board only). It is removed from public view entirely (listings, calendar, and a
     * 404 on its direct URL) and all sign-up interaction is frozen. Reversible via {@see self::republish()}.
     */
    #[Route(
        path: '/{activity}/unpublish',
        name: 'unpublish',
        requirements: ['activity' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted(UserRoles::Board->value)]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_unpublish-" ~ args["activity"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function unpublish(
        #[CurrentUser]
        User $user,
        Activity $activity,
    ): Response {
        if (
            null === $activity->getLiveRevision()
            || $activity->isUnpublished()
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity cannot be unpublished.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        $this->activityAdminService->unpublish(
            $activity,
            $user->getMember(),
        );

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The activity has been unpublished and is no longer publicly visible.'),
        );

        return $this->redirectToRoute('admin/activities/index');
    }

    /**
     * Re-publish a previously unpublished activity (board only), restoring public visibility and sign-up interaction.
     */
    #[Route(
        path: '/{activity}/republish',
        name: 'republish',
        requirements: ['activity' => '\d+'],
        methods: ['POST'],
    )]
    #[IsGranted(UserRoles::Board->value)]
    #[IsCsrfTokenValid(
        id: new Expression('"activity_republish-" ~ args["activity"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function republish(Activity $activity): Response
    {
        if (!$activity->isUnpublished()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This activity is not unpublished.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        $this->activityAdminService->republish($activity);

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The activity has been re-published.'),
        );

        return $this->redirectToRoute('admin/activities/index');
    }

    /**
     * The sign-ups page: a table per (live) sign-up list of everyone who signed up, with their answers,
     * membership type, attendance marking and a bulk-email composer. The table, marking and email all live in the
     * {@see \App\Twig\Components\Activity\Admin\SignupOverview} live component embedded by the template, which
     * re-asserts access on every action.
     */
    #[Route(
        path: '/{activity}/signups',
        name: 'signups',
        requirements: ['activity' => '\d+'],
        methods: ['GET'],
    )]
    public function signups(Activity $activity): Response
    {
        // Organisers (creator, revision author, organ member) and the board may view sign-up details.
        $this->denyAccessUnlessGranted(
            RevisionVoter::VIEW,
            $activity,
        );

        // Sign-ups only exist on the publicly live (approved) revision; there is nothing to show otherwise.
        if (null === $activity->getLiveRevision()) {
            throw $this->createNotFoundException();
        }

        // Organisers lose access a week after the activity ends; the board keeps it (e.g. for GDPR follow-up).
        if (
            !SignupAdminWindow::canView(
                $activity->getEndTime(),
                $this->isGranted(UserRoles::Board->value),
            )
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('You can no longer view the sign-ups of this activity.'),
            );

            return $this->redirectToRoute('admin/activities/index');
        }

        return $this->render(
            'activity/admin/signups.html.twig',
            ['activity' => $activity],
        );
    }

    /**
     * Manually add an external (non-member) subscriber to a sign-up list, for an organiser or the board. Reuses the
     * public sign-up form (organiser mode: name + email + the list's fields, but no captcha and no agreement
     * checkbox) and creates the sign-up already-verified (no double opt-in email). Allowed only while the list is
     * open, mirroring the public sign-up window.
     */
    #[Route(
        path: '/{activity}/signups/{signupList}/add-external',
        name: 'add_external_signup',
        requirements: [
            'activity' => '\d+',
            'signupList' => '\d+',
        ],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function addExternalSignup(
        Activity $activity,
        SignupList $signupList,
        Request $request,
        SignupManager $signupManager,
        ExternalSignupRepository $externalSignupRepository,
    ): Response {
        $this->denyAccessUnlessGranted(
            RevisionVoter::VIEW,
            $activity,
        );

        // The list must be this activity's publicly live list; a crafted id must not reach a draft or another activity.
        if (
            $signupList->getActivity() !== $activity
            || $activity->getLiveRevision() !== $signupList->getRevision()
        ) {
            throw $this->createNotFoundException();
        }

        // A cancelled or unpublished activity has all sign-up interaction frozen.
        if ($activity->isFrozen()) {
            return $this->flashAndBackToSignups(
                $activity,
                AlertTypes::Warning->value,
                $this->translator->trans('Sign-ups are frozen for this activity, so you cannot add a subscriber.'),
            );
        }

        if (!$signupList->isOpen()) {
            return $this->flashAndBackToSignups(
                $activity,
                AlertTypes::Warning->value,
                $this->translator->trans('This sign-up list is not open, so you cannot add a subscriber.'),
            );
        }

        // Externals must never land on a members-only list (the public guest path rejects this too).
        if ($signupList->getOnlyGEWIS()) {
            return $this->flashAndBackToSignups(
                $activity,
                AlertTypes::Warning->value,
                $this->translator->trans(
                    'This sign-up list is for GEWIS members only, so you cannot add an external subscriber.',
                ),
            );
        }

        $form = $this->createForm(
            SignupType::class,
            null,
            [
                'signupList' => $signupList,
                'mode' => SignupType::MODE_ORGANISER,
            ],
        )->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $email = strval($data['email'] ?? '');

            // Mirror the public path: do not create a second sign-up for an address already on this list.
            if (
                null !== $externalSignupRepository->findOneByListAndEmail(
                    $signupList,
                    $email,
                )
            ) {
                return $this->flashAndBackToSignups(
                    $activity,
                    AlertTypes::Warning->value,
                    $this->translator->trans('Someone with this email address is already signed up for this list.'),
                );
            }

            try {
                $signupManager->addExternalSignupByOrganiser(
                    $signupList,
                    strval($data['fullName'] ?? ''),
                    $email,
                    SignupType::extractFieldData(
                        $signupList,
                        $data,
                    ),
                );
            } catch (UniqueConstraintViolationException) {
                // The pre-check above missed a concurrent sign-up for this address: the unique index caught it.
                return $this->flashAndBackToSignups(
                    $activity,
                    AlertTypes::Warning->value,
                    $this->translator->trans('Someone with this email address is already signed up for this list.'),
                );
            }

            return $this->flashAndBackToSignups(
                $activity,
                AlertTypes::Success->value,
                $this->translator->trans('The external subscriber has been added.'),
            );
        }

        return $this->render(
            'activity/admin/add-external-signup.html.twig',
            [
                'form' => $form,
                'activity' => $activity,
                'signupList' => $signupList,
            ],
        );
    }

    /**
     * Re-flash a warning and send the author back to the edit page (shared by the optimistic-lock failure arms).
     */
    private function flashAndBackToEdit(
        Activity $activity,
        string $message,
    ): RedirectResponse {
        $this->addFlash(
            AlertTypes::Warning->value,
            $message,
        );

        return $this->redirectToRoute(
            'admin/activities/edit',
            ['activity' => $activity->getId()],
        );
    }

    /**
     * Flash a message and send the organiser back to the sign-ups page (shared by the add-external-signup arms).
     */
    private function flashAndBackToSignups(
        Activity $activity,
        string $type,
        string $message,
    ): RedirectResponse {
        $this->addFlash(
            $type,
            $message,
        );

        return $this->redirectToRoute(
            'admin/activities/signups',
            ['activity' => $activity->getId()],
        );
    }

    /**
     * Session key under which the version an in-place edit started from is stamped (per activity), so the
     * optimistic-lock check on save reads a server-trusted base version instead of a client-submitted one.
     */
    private function editVersionKey(Activity $activity): string
    {
        return 'activity-edit-base-version-' . strval($activity->getId());
    }
}
