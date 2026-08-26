<?php

declare(strict_types=1);

namespace App\Controller\Career;

use App\Controller\Application\HandlesFormFlowTrait;
use App\Controller\Application\HoldsEditLockTrait;
use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Application\Enums\ReviseRefusal;
use App\Entity\Career\Company;
use App\Entity\Career\CompanyRevision;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Form\Career\CompanyProfile\CompanyProfileData;
use App\Form\Career\CompanyProfile\CompanyProfileFlowType;
use App\Repository\Career\CompanyAuditLogRepository;
use App\Repository\Career\CompanyRevisionCommentRepository;
use App\Repository\Career\VacancyRevisionRepository;
use App\Security\Application\RevisionVoter;
use App\Service\Application\RevisionReviewService;
use App\Service\Career\CareerOverviewCountsProvider;
use App\Service\Career\CompanyDraftService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

/**
 * Companies, from the board's side: the list, adding one, what a company looks like right now, and revising its
 * profile. The profile goes through the same review chain a company's own proposal does, so even a board edit is a
 * draft until somebody approves it.
 */
#[IsGranted(
    attribute: UserRoles::CompanyAdmin->value,
    message: 'You are not allowed to administer companies.',
)]
#[Route(
    path: '/admin/career',
    name: 'admin/career/',
)]
class AdminController extends AbstractController
{
    use HandlesFormFlowTrait;
    use HoldsEditLockTrait;

    public function __construct(
        private readonly CompanyAuditLogRepository $auditLogRepository,
        private readonly CompanyRevisionCommentRepository $commentRepository,
        private readonly VacancyRevisionRepository $vacancyRevisionRepository,
        private readonly CareerOverviewCountsProvider $overviewCounts,
        private readonly CompanyDraftService $companyDraftService,
        private readonly RevisionReviewService $revisionReviewService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '',
        name: 'index',
    )]
    public function index(): Response
    {
        return $this->render(
            'career/admin/index.html.twig',
            ['counts' => $this->overviewCounts->counts()],
        );
    }

    #[Route(
        path: '/companies/create',
        name: 'companies/create',
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
        $company = new Company();
        $company->setPublished(false);

        $revision = new CompanyRevision();
        $revision->setAuthor($user->getMember());
        $company->addRevision($revision);
        $company->setCurrentRevision($revision);

        $run = $this->flowRun($request);

        if ($run instanceof RedirectResponse) {
            return $run;
        }

        $flow = $this->createFlow(
            CompanyProfileFlowType::class,
            new CompanyProfileData(),
            [
                'admin' => true,
                'flow_key' => $run,
                'finish_label' => $this->translator->trans('Save draft'),
            ],
        );
        $flow->handleRequest($request);

        if (!$flow->isFinished()) {
            $this->flashRejectedStep(
                $flow,
                $this->translator,
            );

            return $this->render(
                'career/admin/create.html.twig',
                ['form' => $flow->getStepForm()],
            );
        }

        $data = $flow->getData();
        assert($data instanceof CompanyProfileData);
        $data->applyTo(
            $company,
            $revision,
            true,
        );

        $this->warnOnRefusedLogo($this->companyDraftService->create(
            $company,
            $revision,
            $user,
            ...$this->uploadedLogos($flow),
        ));
        $flow->reset();

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('The company was saved as a draft. Submit it for review when you are ready.'),
        );

        return $this->backToCompany($company);
    }

    #[Route(
        path: '/companies/{company}',
        name: 'companies/view',
        requirements: ['company' => '\d+'],
        methods: ['GET'],
    )]
    public function view(Company $company): Response
    {
        return $this->render(
            'career/admin/view.html.twig',
            [
                'company' => $company,
                'timeline' => $this->auditLogRepository->findRecentForCompany($company),
            ],
        );
    }

    #[Route(
        path: '/companies/{company}/vacancies',
        name: 'companies/vacancies',
        requirements: ['company' => '\d+'],
        methods: ['GET'],
    )]
    public function vacancies(Company $company): Response
    {
        return $this->render(
            'career/admin/vacancies.html.twig',
            [
                'company' => $company,
                'awaitingReview' => $this->vacancyRevisionRepository->countForReviewOf($company),
            ],
        );
    }

    #[Route(
        path: '/companies/{company}/edit',
        name: 'companies/edit',
        requirements: ['company' => '\d+'],
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function edit(
        Request $request,
        Company $company,
        #[CurrentUser]
        User $user,
    ): Response {
        $this->denyAccessUnlessGranted(
            RevisionVoter::SUBMIT,
            $company,
        );

        $current = $company->getCurrentRevision();
        if (null === $current) {
            throw $this->createNotFoundException();
        }

        // While the profile is with the committee it belongs to the committee; editing it again would pull the ground
        // out from under the review that is running.
        if (!$current->getStatus()->isEditableByAuthor()) {
            $this->addFlash(
                AlertTypes::Warning->value,
                $this->translator->trans('This profile is not a draft. Revise it to start a new one.'),
            );

            return $this->backToCompany($company);
        }

        $lock = $this->editLockService->acquire(
            $company,
            $user,
            $request->query->getBoolean('take') && $this->isGranted(UserRoles::Board->value),
        );
        if (null === $lock) {
            return $this->render(
                'career/admin/edit-locked.html.twig',
                [
                    'company' => $company,
                    'lock' => $this->editLockService->blockingLock(
                        $company,
                        $user,
                    ),
                ],
            );
        }

        $run = $this->flowRun($request);

        if ($run instanceof RedirectResponse) {
            return $run;
        }

        $flow = $this->createFlow(
            CompanyProfileFlowType::class,
            CompanyProfileData::fromCompany(
                $company,
                $current,
            ),
            [
                'admin' => true,
                'flow_key' => $run,
                'finish_label' => $this->translator->trans('Save changes'),
                'has_square_logo' => null !== $current->getSquareLogo(),
                'has_banner_logo' => null !== $current->getBannerLogo(),
            ],
        );
        $flow->handleRequest($request);

        if (!$flow->isFinished()) {
            $this->flashRejectedStep(
                $flow,
                $this->translator,
            );

            return $this->render(
                'career/admin/edit.html.twig',
                [
                    'form' => $flow->getStepForm(),
                    'company' => $company,
                    'comments' => $this->commentRepository->findThreadForCompany($company),
                ],
            );
        }

        $data = $flow->getData();
        assert($data instanceof CompanyProfileData);
        $data->applyTo(
            $company,
            $current,
            true,
        );

        $this->warnOnRefusedLogo($this->companyDraftService->saveDraft(
            $company,
            $current,
            $user,
            ...$this->uploadedLogos($flow),
        ));
        $flow->reset();

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('Changes saved. Submit the revision for review when you are ready.'),
        );

        return $this->backToCompany($company);
    }

    #[Route(
        path: '/companies/{company}/edit/ping',
        name: 'companies/edit_ping',
        requirements: ['company' => '\\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"career_company_edit_lock-" ~ args["company"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function editPing(
        #[CurrentUser]
        User $user,
        Company $company,
    ): JsonResponse {
        return $this->pingLock(
            $company,
            $user,
        );
    }

    #[Route(
        path: '/companies/{company}/edit/release',
        name: 'companies/edit_release',
        requirements: ['company' => '\\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"career_company_edit_lock-" ~ args["company"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function editRelease(
        #[CurrentUser]
        User $user,
        Company $company,
    ): JsonResponse {
        return $this->releaseLock(
            $company,
            $user,
        );
    }

    /**
     * Start a new draft from the profile as it stands, which is how an approved profile is changed at all.
     */
    #[Route(
        path: '/companies/{company}/revise',
        name: 'companies/revise',
        requirements: ['company' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        id: new Expression('"company_revise-" ~ args["company"].getId()'),
        tokenKey: '_csrf_token',
    )]
    public function revise(
        Company $company,
        #[CurrentUser]
        User $user,
    ): Response {
        $this->denyAccessUnlessGranted(
            RevisionVoter::SUBMIT,
            $company,
        );

        $current = $company->getCurrentRevision();
        if (null === $current) {
            throw $this->createNotFoundException();
        }

        $refusal = $current->getStatus()->reviseRefusal();
        if (null !== $refusal) {
            // trans() is called per arm (not around the match) so each literal stays statically extractable.
            $this->addFlash(
                AlertTypes::Warning->value,
                match ($refusal) {
                    ReviseRefusal::AlreadyADraft => $this->translator->trans('There is already a draft to work on.'),
                    ReviseRefusal::UnderReview => $this->translator->trans(
                        'This profile is with the committee and cannot be revised until they have decided.',
                    ),
                    ReviseRefusal::Closed => $this->translator->trans(
                        'This profile was closed by the board and can no longer be revised.',
                    ),
                },
            );

            return $this->backToCompany($company);
        }

        $this->revisionReviewService->startDraft(
            $current,
            $user,
        );

        $this->addFlash(
            AlertTypes::Success->value,
            $this->translator->trans('A new draft was created. Edit it and submit it for review.'),
        );

        return $this->redirectToRoute(
            'admin/career/companies/edit',
            ['company' => $company->getId()],
        );
    }

    private function backToCompany(Company $company): Response
    {
        return $this->redirectToRoute(
            'admin/career/companies/view',
            ['company' => $company->getId()],
        );
    }

    /**
     * The two logo uploads the revision form carries, in the order the draft service takes them.
     *
     * @param FormInterface<Company> $form
     *
     * @return array{0: ?UploadedFile, 1: ?UploadedFile}
     */
    private function uploadedLogos(FormInterface $form): array
    {
        $logoForm = $form->get(CompanyProfileData::STEP_LOGO);
        $square = $logoForm->get('squareLogoFile')->getData();
        $banner = $logoForm->get('bannerLogoFile')->getData();

        return [
            $square instanceof UploadedFile ? $square : null,
            $banner instanceof UploadedFile ? $banner : null,
        ];
    }

    private function warnOnRefusedLogo(bool $stored): void
    {
        if ($stored) {
            return;
        }

        $this->addFlash(
            AlertTypes::Warning->value,
            $this->translator->trans('The logo could not be stored, so the previous one is still in use.'),
        );
    }
}
