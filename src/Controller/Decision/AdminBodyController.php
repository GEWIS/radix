<?php

declare(strict_types=1);

namespace App\Controller\Decision;

use App\Controller\Application\AbstractRevisionController;
use App\Controller\Application\HoldsEditLockTrait;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\ReviseRefusal;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganInformation;
use App\Entity\Decision\SubDecision\FoundationReference;
use App\Entity\Decision\SubDecision\Installation;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Form\Decision\OrganInformationType;
use App\Repository\Decision\OrganInformationRevisionCommentRepository;
use App\Security\Application\RevisionVoter;
use App\Service\Decision\OrganImageUploadService;
use App\Service\Decision\OrganPageService;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_filter;

/**
 * Where a body writes its own page. A body never edits what is on the website: it works on a draft, submits it, and the
 * board decides, which is what {@see AdminBodyApprovalController} is for.
 *
 * Which bodies somebody may write for is the organs they are installed in, and the board may write for all of them.
 * That is the voter's answer rather than this controller's, because the page names the body it belongs to and the voter
 * already reads organ membership off it.
 */
#[IsGranted(
    attribute: new Expression(
        'is_granted("' . UserRoles::ActiveMember->value . '") or is_granted("' . UserRoles::Board->value . '")',
    ),
    message: 'You are not allowed to administer bodies.',
)]
#[Route(
    path: '/admin/bodies',
    name: 'admin/bodies/',
)]
class AdminBodyController extends AbstractRevisionController
{
    use HoldsEditLockTrait;

    public function __construct(
        private readonly OrganInformationRevisionCommentRepository $commentRepository,
        private readonly OrganImageUploadService $imageUploadService,
        private readonly OrganPageService $organPageService,
    ) {
    }

    /**
     * The bodies this member may write for, with what is happening to each page.
     */
    #[Route(
        path: '',
        name: 'index',
    )]
    public function index(): Response
    {
        // The table itself is `Decision:Admin:BodyPageOverview`, which pages over the bodies on its own.
        return $this->render('decision/admin/bodies/index.html.twig');
    }

    /**
     * The page as it stands, with whatever the body may do to it next. A body with no page at all is offered the chance
     * to start one.
     */
    #[Route(
        path: '/{organ}',
        name: 'view',
        requirements: ['organ' => '\d+'],
    )]
    public function view(Organ $organ): Response
    {
        $page = $organ->getOrganInformation();
        // Whoever administers the register comes here for the body's composition rather than for its page, so the
        // page's own permissions decide what they are shown of it rather than whether they are let in at all.
        $register = $this->isGranted(UserRoles::DatabaseReadOnly->value);

        if (null !== $page) {
            if (
                !$register
                && !$this->isGranted(
                    RevisionVoter::VIEW,
                    $page,
                )
            ) {
                throw $this->createAccessDeniedException('You are not allowed to read this body\'s page.');
            }
        } elseif (
            !$register
            && !$this->mayStartAPage($organ)
        ) {
            throw $this->createAccessDeniedException('You are not allowed to write this body\'s page.');
        }

        return $this->render(
            'decision/admin/bodies/view.html.twig',
            [
                'organ' => $organ,
                // Never 'page': the base layout treats a defined `page` as a custom page and builds an hreflang link
                // out of it.
                'information' => $page,
                'revision' => $page?->getCurrentRevision(),
                'comments' => null === $page
                    ? []
                    : $this->commentRepository->findThreadForOrganInformation($page),
                // The page half is only shown to somebody who may write it; a register reader who may not still sees
                // the composition below it.
                'showsPage' => null === $page
                    ? $this->mayStartAPage($organ)
                    : $this->isGranted(
                        RevisionVoter::VIEW,
                        $page,
                    ),
                ...($register
                    ? [
                        'foundation' => $organ->getFoundation(),
                        // A foundation is referenced by discharges and abrogations as well; only installations say
                        // who is in it.
                        'installations' => array_filter(
                            $organ->getFoundation()->getReferences()->toArray(),
                            static fn (FoundationReference $reference): bool => $reference instanceof Installation,
                        ),
                    ]
                    : []),
            ],
        );
    }

    #[Route(
        path: '/{organ}/edit',
        name: 'edit',
        requirements: ['organ' => '\d+'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        Organ $organ,
        #[CurrentUser]
        User $user,
    ): Response {
        $page = $this->pageToEdit(
            $organ,
            $user,
        );
        $draft = $page->getCurrentRevision();

        if (
            null === $draft
            || !$draft->getStatus()->isEditableByAuthor()
        ) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This page is not a draft right now. Revise it to start a new one.'),
            );

            return $this->redirectToRoute(
                'admin/bodies/view',
                ['organ' => $organ->getId()],
            );
        }

        if (
            null === $this->editLockService->acquire(
                $page,
                $user,
            )
        ) {
            return $this->render(
                'decision/admin/bodies/edit-locked.html.twig',
                [
                    'organ' => $organ,
                    'lock' => $this->editLockService->blockingLock(
                        $page,
                        $user,
                    ),
                ],
            );
        }

        $form = $this->createForm(
            OrganInformationType::class,
            $page,
        )->handleRequest($request);

        if (
            !$form->isSubmitted()
            || !$form->isValid()
        ) {
            return $this->render(
                'decision/admin/bodies/edit.html.twig',
                [
                    'form' => $form,
                    'organ' => $organ,
                    'information' => $page,
                    'revision' => $draft,
                    // The picker holds the frame to a minimum width of the original, which it cannot read off the
                    // rendition it draws on.
                    'bannerSourceWidth' => $this->imageUploadService->sourceWidth($draft->getBannerSource()),
                    'logoSourceWidth' => $this->imageUploadService->sourceWidth($draft->getLogoSource()),
                ],
            );
        }

        $revisionForm = $form->get('currentRevision');
        $banner = $revisionForm->get('bannerFile')->getData();
        $logo = $revisionForm->get('logoFile')->getData();

        $stored = $this->organPageService->applyImages(
            $draft,
            $banner instanceof UploadedFile ? $banner : null,
            $logo instanceof UploadedFile ? $logo : null,
            $revisionForm->get('bannerCropData')->getData(),
            $revisionForm->get('logoCropData')->getData(),
        );

        $this->organPageService->saveDraft(
            $page,
            $draft,
            $user,
        );

        // The text is saved either way, so what went wrong is said rather than hidden behind the usual reassurance: a
        // body that is told its page is saved would submit it for review with the old image still on it.
        if ($stored) {
            $this->addFlash(
                AlertTypes::Success->value,
                $this->translator->trans('Your changes are saved. Submit them for review when you are ready.'),
            );
        } else {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans(
                    'Your changes are saved, but an image could not be stored. Try uploading it again.',
                ),
            );
        }

        return $this->redirectToRoute(
            'admin/bodies/view',
            ['organ' => $organ->getId()],
        );
    }

    #[Route(
        path: '/{organ}/edit/ping',
        name: 'edit_ping',
        requirements: ['organ' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: 'body_edit_lock',
        tokenKey: '_csrf_token',
    )]
    public function editPing(
        Organ $organ,
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        $page = $organ->getOrganInformation();
        if (null === $page) {
            throw $this->createNotFoundException();
        }

        return $this->pingLock(
            $page,
            $user,
        );
    }

    #[Route(
        path: '/{organ}/edit/release',
        name: 'edit_release',
        requirements: ['organ' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: 'body_edit_lock',
        tokenKey: '_csrf_token',
    )]
    public function editRelease(
        Organ $organ,
        #[CurrentUser]
        User $user,
    ): JsonResponse {
        $page = $organ->getOrganInformation();
        if (null === $page) {
            throw $this->createNotFoundException();
        }

        return $this->releaseLock(
            $page,
            $user,
        );
    }

    /**
     * Start a fresh draft off whatever the page says now, which is the only way to change something the board has
     * already decided on.
     */
    #[Route(
        path: '/{organ}/revise',
        name: 'revise',
        requirements: ['organ' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"body_revise-" ~ args["organ"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function revise(
        Organ $organ,
        #[CurrentUser]
        User $user,
    ): Response {
        $page = $this->pageToEdit(
            $organ,
            $user,
        );
        $current = $page->getCurrentRevision();

        if (null === $current) {
            return $this->redirectToRoute(
                'admin/bodies/edit',
                ['organ' => $organ->getId()],
            );
        }

        $refusal = $current->getStatus()->reviseRefusal();

        // A draft that is already there is what the body wants to work on, which is not worth a warning.
        if (ReviseRefusal::AlreadyADraft === $refusal) {
            return $this->redirectToRoute(
                'admin/bodies/edit',
                ['organ' => $organ->getId()],
            );
        }

        if (ReviseRefusal::UnderReview === $refusal) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans(
                    'This page is with the board. Wait for their decision before revising it again.',
                ),
            );

            return $this->redirectToRoute(
                'admin/bodies/view',
                ['organ' => $organ->getId()],
            );
        }

        if (ReviseRefusal::Closed === $refusal) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This page was closed. Get in touch with the board to reopen it.'),
            );

            return $this->redirectToRoute(
                'admin/bodies/view',
                ['organ' => $organ->getId()],
            );
        }

        $this->revisionReviewService->startDraft(
            $current,
            $user,
        );

        return $this->redirectToRoute(
            'admin/bodies/edit',
            ['organ' => $organ->getId()],
        );
    }

    /**
     * The page to edit, starting one when this body has never had one. Creating it is the same right as editing it: an
     * installed member or the board.
     */
    private function pageToEdit(
        Organ $organ,
        User $user,
    ): OrganInformation {
        $page = $organ->getOrganInformation();

        if (null !== $page) {
            $this->denyAccessUnlessGranted(
                RevisionVoter::SUBMIT,
                $page,
            );

            if (null === $page->getCurrentRevision()) {
                $this->organPageService->startFirstDraft(
                    $page,
                    $user,
                );
            }

            return $page;
        }

        if (!$this->mayStartAPage($organ)) {
            throw $this->createAccessDeniedException('You are not allowed to write this body\'s page.');
        }

        return $this->organPageService->createPage(
            $organ,
            $user,
        );
    }

    /**
     * Whether this member may write a page for a body that has none yet. The voter needs a page to read the body off,
     * so before there is one the same question is answered here: the board, or somebody installed in the body.
     */
    private function mayStartAPage(Organ $organ): bool
    {
        if ($this->isGranted(UserRoles::Board->value)) {
            return true;
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return false;
        }

        foreach ($user->getMember()->getCurrentOrganInstallations() as $installation) {
            if ($installation->getOrgan()->getId() === $organ->getId()) {
                return true;
            }
        }

        return false;
    }
}
