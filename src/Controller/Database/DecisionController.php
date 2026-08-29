<?php

declare(strict_types=1);

namespace App\Controller\Database;

use App\Entity\Application\Enums\AlertTypes;
use App\Entity\Database\Decision;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\User\Enums\UserRoles;
use App\Exception\Database\AnnulmentNotPossible;
use App\Form\Database\AbolishType;
use App\Form\Database\AnnulmentType;
use App\Form\Database\Board\CandidacyType as BoardCandidacyType;
use App\Form\Database\Board\DischargeType as BoardDischargeType;
use App\Form\Database\Board\InstallType as BoardInstallType;
use App\Form\Database\Board\ReleaseType as BoardReleaseType;
use App\Form\Database\BudgetType;
use App\Form\Database\ContinuationType;
use App\Form\Database\FoundationType;
use App\Form\Database\InstallType;
use App\Form\Database\Key\GrantType as KeyGrantType;
use App\Form\Database\Key\WithdrawType as KeyWithdrawType;
use App\Form\Database\Member\SuspensionType as MemberSuspensionType;
use App\Form\Database\Member\WarningType as MemberWarningType;
use App\Form\Database\MemberFunctionType;
use App\Form\Database\MinutesType;
use App\Form\Database\OrganRegulationType;
use App\Form\Database\OtherTranslationType;
use App\Form\Database\OtherType;
use App\Form\Report\ExportType;
use App\Service\Database\Meeting as MeetingService;
use App\ViewModel\Database\UntranslatedDecision;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function assert;
use function ceil;
use function in_array;
use function max;
use function sprintf;
use function Symfony\Component\Translation\t;

// Deliberately without a class-level prefix: the decision list has always been served from `/export`, which cannot
// sit under the prefix the other actions share.
#[IsGranted(UserRoles::Board->value)]
final class DecisionController extends AbstractController
{
    /**
     * Every kind of decision that can be recorded, keyed by the name it is entered and posted under.
     *
     * The key is also the partial that shows the form, and the order is the order of the tabs.
     */
    private const array FORM_TYPES = [
        'budget' => BudgetType::class,
        'organ_regulation' => OrganRegulationType::class,
        'foundation' => FoundationType::class,
        'continuation' => ContinuationType::class,
        'abolish' => AbolishType::class,
        'install' => InstallType::class,
        'board_install' => BoardInstallType::class,
        'board_release' => BoardReleaseType::class,
        'board_discharge' => BoardDischargeType::class,
        'board_candidacy' => BoardCandidacyType::class,
        'key_grant' => KeyGrantType::class,
        'key_withdraw' => KeyWithdrawType::class,
        'member_warning' => MemberWarningType::class,
        'member_suspension' => MemberSuspensionType::class,
        'annulment' => AnnulmentType::class,
        'minutes' => MinutesType::class,
        'other' => OtherType::class,
    ];

    private const int TRANSLATIONS_PAGE_SIZE = 25;
    private const array TRANSLATIONS_PAGE_SIZES = [
        10,
        25,
        50,
        100,
    ];

    public function __construct(
        private readonly MeetingService $meetingService,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * The decisions a decision can refer to, such as the one an annulment takes back.
     */
    #[Route(
        path: '/meetings/decisions/search',
        name: 'decision_decision_search',
        methods: ['GET'],
    )]
    public function search(Request $request): JsonResponse
    {
        $meetingType = $request->query->get('meeting_type');
        $meetingNumber = $request->query->get('meeting_number');

        return $this->json($this->meetingService->searchDecisions(
            (string) $request->query->get(
                'q',
                '',
            ),
            null === $meetingType ? null : MeetingTypes::tryFrom((string) $meetingType),
            null === $meetingNumber ? null : (int) $meetingNumber,
            $request->query->getInt('point'),
            $request->query->getInt('decision'),
            // Asked for by the lookup that picks a virtual counterpart: only a virtual decision is one, and one
            // that is already somebody's counterpart is spoken for.
            $request->query->getBoolean('only_virtual'),
        ));
    }

    /**
     * Every form a decision can be entered with, for one place in a meeting.
     */
    #[Route(
        path: '/meetings/{type}/{number}/points/{point}/decisions/{decision}',
        name: 'decision_decision_create',
        requirements: [
            'type' => 'ALV|BV|VV|Virt',
            'number' => '-?\d+',
            'point' => '\d+',
            'decision' => '\d+',
        ],
        methods: ['GET'],
    )]
    #[IsGranted(UserRoles::DatabaseAdmin->value)]
    public function create(
        MeetingTypes $type,
        int $number,
        int $point,
        int $decision,
    ): Response {
        $meeting = $this->meetingService->getMeeting(
            $type,
            $number,
        );

        if (null === $meeting) {
            throw $this->createNotFoundException();
        }

        if (
            $this->meetingService->decisionExists(
                $type,
                $number,
                $point,
                $decision,
            )
        ) {
            return $this->render(
                'database/decision/decision/create.html.twig',
                [
                    'meeting' => $meeting,
                    'point' => $point,
                    'decision' => $decision,
                    'error' => true,
                ],
            );
        }

        $forms = [];

        foreach (self::FORM_TYPES as $name => $formType) {
            $forms[$name] = $this->createForm(
                $formType,
                new Decision(),
                [
                    'meeting' => $meeting,
                    'point' => $point,
                    'number' => $decision,
                ],
            )->createView();
        }

        $options = $this->meetingService->getDecisionOptions();

        return $this->render(
            'database/decision/decision/create.html.twig',
            [
                'meeting' => $meeting,
                'point' => $point,
                'decision' => $decision,
                'forms' => $forms,
                'installs' => $options->boardInstallations,
                'releasable_installs' => $options->releasableBoardInstallations,
                'grants' => $options->keyGrants,
                'member_function_form' => $this->memberFunctionForm(),
            ],
        );
    }

    /**
     * Record one decision, entered with one of the forms above.
     */
    #[Route(
        path: '/meetings/decisions/form/{form}',
        name: 'decision_decision_form',
        requirements: ['form' => '[a-z][a-z_]*'],
        methods: ['POST'],
    )]
    #[IsGranted(UserRoles::DatabaseAdmin->value)]
    public function form(
        Request $request,
        string $form,
    ): Response {
        if (
            !array_key_exists(
                $form,
                self::FORM_TYPES,
            )
        ) {
            throw $this->createNotFoundException();
        }

        $decisionForm = $this->createForm(
            self::FORM_TYPES[$form],
            new Decision(),
        );
        $decisionForm->handleRequest($request);

        if (
            $decisionForm->isSubmitted()
            && $decisionForm->isValid()
        ) {
            $decision = $decisionForm->getData();
            assert($decision instanceof Decision);

            try {
                $recorded = $this->meetingService->recordDecision($decision);

                return $this->render(
                    'database/decision/decision/created.html.twig',
                    [
                        'decision' => $recorded,
                        'contents' => $recorded->contents,
                        'warnings' => $recorded->warnings,
                    ],
                );
            } catch (AnnulmentNotPossible $e) {
                // The decision that is annulled is picked through the lookup on the `name` field, so that is where
                // the reason it cannot be annulled belongs.
                $decisionForm->get('name')->addError(new FormError($e->getMessage()));
            }
        }

        $options = $this->meetingService->getDecisionOptions();

        return $this->render(
            'database/decision/decision/form.html.twig',
            [
                'type' => $form,
                'form' => $decisionForm,
                // Relieving a board member is the only decision that leaves out those who have been relieved already.
                'installs' => 'board_release' === $form
                    ? $options->releasableBoardInstallations
                    : $options->boardInstallations,
                'grants' => $options->keyGrants,
                'member_function_form' => $this->memberFunctionForm(),
            ],
        );
    }

    #[Route(
        path: '/meetings/decisions/translations',
        name: 'decision_decision_translations',
        methods: ['GET'],
    )]
    #[IsGranted(UserRoles::DatabaseAdmin->value)]
    public function translations(
        #[MapQueryParameter]
        int $page = 1,
        #[MapQueryParameter]
        int $pageSize = self::TRANSLATIONS_PAGE_SIZE,
    ): Response {
        return $this->renderTranslations(
            $page,
            $pageSize,
        );
    }

    #[Route(
        path: '/meetings/decisions/translations/{type}/{number}/{point}/{decision}/{sequence}',
        name: 'decision_decision_translate',
        requirements: [
            'type' => 'ALV|BV|VV|Virt',
            'number' => '-?\d+',
            'point' => '\d+',
            'decision' => '\d+',
            'sequence' => '\d+',
        ],
        methods: ['POST'],
    )]
    #[IsGranted(UserRoles::DatabaseAdmin->value)]
    public function translate(
        Request $request,
        MeetingTypes $type,
        int $number,
        int $point,
        int $decision,
        int $sequence,
        #[MapQueryParameter]
        int $page = 1,
        #[MapQueryParameter]
        int $pageSize = self::TRANSLATIONS_PAGE_SIZE,
    ): Response {
        $subdecision = $this->meetingService->getUntranslatedDecision(
            $type,
            $number,
            $point,
            $decision,
            $sequence,
        );

        if (null === $subdecision) {
            throw $this->createNotFoundException();
        }

        $form = $this->translationForm(
            $subdecision->getMeetingType(),
            $subdecision->getMeetingNumber(),
            $subdecision->getDecisionPoint(),
            $subdecision->getDecisionNumber(),
            $subdecision->getSequence(),
            $page,
            $pageSize,
        );
        $form->handleRequest($request);

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            /** @var array{contentEN: string} $data */
            $data = $form->getData();

            $subdecision->setContentEN($data['contentEN']);
            $this->meetingService->translateDecision($subdecision);

            $this->addFlash(
                AlertTypes::Success->value,
                t('The decision now reads in English as well.'),
            );

            return $this->redirectToRoute(
                'decision_decision_translations',
                [
                    'page' => $page,
                    'pageSize' => $pageSize,
                ],
            );
        }

        return $this->renderTranslations(
            $page,
            $pageSize,
            $form,
        );
    }

    /**
     * The submitted form takes the place of the one built for its row, so its errors land on the field they were
     * written in.
     *
     * @param ?FormInterface<array<string, mixed>|null> $submitted
     */
    private function renderTranslations(
        int $page,
        int $pageSize,
        ?FormInterface $submitted = null,
    ): Response {
        if (
            !in_array(
                $pageSize,
                self::TRANSLATIONS_PAGE_SIZES,
                true,
            )
        ) {
            $pageSize = self::TRANSLATIONS_PAGE_SIZE;
        }

        $page = max(
            1,
            $page,
        );
        $result = $this->meetingService->getUntranslatedDecisions(
            $page,
            $pageSize,
        );
        $totalPages = max(
            1,
            (int) ceil($result['total'] / $pageSize),
        );

        // Translating the last decision on a page empties it, as does asking for a page that is not there.
        if (
            [] === $result['items']
            && $page > $totalPages
        ) {
            return $this->redirectToRoute(
                'decision_decision_translations',
                [
                    'page' => $totalPages,
                    'pageSize' => $pageSize,
                ],
            );
        }

        $rows = [];
        $forms = [];

        foreach ($result['items'] as $subdecision) {
            $name = self::translationFormName(
                $subdecision->getMeetingType(),
                $subdecision->getMeetingNumber(),
                $subdecision->getDecisionPoint(),
                $subdecision->getDecisionNumber(),
                $subdecision->getSequence(),
            );
            $rows[] = UntranslatedDecision::fromSubDecision(
                $subdecision,
                $name,
            );
            $forms[$name] = (
                null !== $submitted
                && $name === $submitted->getName()
                    ? $submitted
                    : $this->translationForm(
                        $subdecision->getMeetingType(),
                        $subdecision->getMeetingNumber(),
                        $subdecision->getDecisionPoint(),
                        $subdecision->getDecisionNumber(),
                        $subdecision->getSequence(),
                        $page,
                        $pageSize,
                    )
            )->createView();
        }

        return $this->render(
            'database/decision/decision/translations.html.twig',
            [
                'rows' => $rows,
                'forms' => $forms,
                'currentPage' => $page,
                'pageSize' => $pageSize,
                'totalPages' => $totalPages,
                'totalCount' => $result['total'],
            ],
        );
    }

    /**
     * Named after the decision: a page holds one of these per decision, and two forms of the same name would answer
     * for each other's fields.
     *
     * @return FormInterface<array<string, mixed>|null>
     */
    private function translationForm(
        MeetingTypes $type,
        int $number,
        int $point,
        int $decision,
        int $sequence,
        int $page,
        int $pageSize,
    ): FormInterface {
        return $this->formFactory->createNamed(
            self::translationFormName(
                $type,
                $number,
                $point,
                $decision,
                $sequence,
            ),
            OtherTranslationType::class,
            null,
            [
                'action' => $this->generateUrl(
                    'decision_decision_translate',
                    [
                        'type' => $type->value,
                        'number' => $number,
                        'point' => $point,
                        'decision' => $decision,
                        'sequence' => $sequence,
                        'page' => $page,
                        'pageSize' => $pageSize,
                    ],
                ),
            ],
        );
    }

    private static function translationFormName(
        MeetingTypes $type,
        int $number,
        int $point,
        int $decision,
        int $sequence,
    ): string {
        return sprintf(
            'translation_%s_%d_%d_%d_%d',
            $type->value,
            $number,
            $point,
            $decision,
            $sequence,
        );
    }

    /**
     * The decisions of a set of meetings, as the list that is published.
     */
    #[Route(
        path: '/meetings/export',
        name: 'decision_export_index',
        methods: [
            'GET',
            'POST',
        ],
    )]
    public function export(Request $request): Response
    {
        $form = $this->createForm(ExportType::class);
        $form->handleRequest($request);

        $latex = null;

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            /** @var array{meetings: string[]} $data */
            $data = $form->getData();

            // The decision list is a LaTeX document, which is shown as text to be pasted into a LaTeX editor rather
            // than as the page itself.
            $latex = $this->renderView(
                'database/decision/export/decisions.tex.twig',
                [
                    'categories' => $this->meetingService->exportDecisions($data['meetings']),
                ],
            );
        }

        return $this->render(
            'database/decision/export/index.html.twig',
            [
                'form' => $form,
                'latex' => $latex,
            ],
        );
    }

    /**
     * The function a member is given while an organ's membership is being edited.
     *
     * Being a member of the organ, or being an inactive one, is not offered: the page adds those installations itself
     * alongside whatever function is picked here.
     */
    private function memberFunctionForm(): FormInterface
    {
        return $this->createForm(
            MemberFunctionType::class,
            null,
            ['include_administrative' => false],
        );
    }
}
