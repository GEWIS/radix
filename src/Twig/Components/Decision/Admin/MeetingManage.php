<?php

declare(strict_types=1);

namespace App\Twig\Components\Decision\Admin;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingActivityLog;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingPoint;
use App\Entity\Decision\MeetingReferenceSelection;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\User\Enums\UserRoles;
use App\Entity\User\User;
use App\Exception\Database\AnnulmentNotPossible;
use App\Exception\Database\DecisionStillReferenced;
use App\Repository\Decision\MeetingActivityLogRepository;
use App\Repository\Decision\MeetingDocumentRepository;
use App\Repository\Decision\MeetingPointRepository;
use App\Repository\Decision\ReferenceDocumentRepository;
use App\Security\User\SudoVoter;
use App\Service\Database\Meeting as DatabaseMeetingService;
use App\Service\Decision\MeetingDocumentService;
use App\Service\Decision\MeetingLocalDetailsService;
use App\Service\Decision\MeetingMinutesService;
use App\Service\Decision\MeetingQueryService;
use App\Service\Decision\ReferenceDocumentService;
use App\Service\Decision\VersionLabelSuggester;
use App\ViewModel\Database\MeetingView as LedgerMeetingView;
use App\ViewModel\Decision\MeetingReadiness;
use App\ViewModel\Decision\MeetingView;
use DateTime;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

use function array_map;
use function array_values;
use function assert;
use function strtoupper;
use function strval;
use function trim;

/**
 * The management page of one meeting: inline-editable agenda points with their documents, and the minutes. Edits
 * persist as they are made (blur/change), so there is no page-level form; uploads go through the XHR endpoints of
 * {@see \App\Controller\Decision\AdminMeetingController}, which trigger a re-render of this component on success.
 *
 * Like the sign-up overview this component writes to the database, so it re-asserts access on every action: a live
 * request is independent of the gated page that embedded the component.
 */
#[AsLiveComponent(
    name: 'Decision:Admin:MeetingManage',
    template: 'components/Decision/Admin/MeetingManage.html.twig',
)]
#[IsGranted(UserRoles::DatabaseAdmin->value)]
final class MeetingManage
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $type;

    #[LiveProp]
    public int $number;

    /**
     * Pending inline edits of agenda points, keyed by point id: `{number?: string, title?: string}`. Applied and
     * cleared by {@see syncEdits()} on the next request.
     *
     * @var array<int|string, array<string, string>>
     */
    #[LiveProp(writable: true)]
    public array $pointEdits = [];

    /**
     * Pending inline renames of documents, keyed by document id: `{name?: string}`.
     *
     * @var array<int|string, array<string, string>>
     */
    #[LiveProp(writable: true)]
    public array $documentEdits = [];

    /**
     * Pending version pins of reference selections, keyed by library document id.
     *
     * @var array<int|string, string>
     */
    #[LiveProp(writable: true)]
    public array $pins = [];

    /**
     * Pending time and place edits: `{startTime?: string, location?: string}`.
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $details = [];

    // Transient, rendered once in this component's own markup.
    public ?string $feedback = null;
    public ?string $savedAt = null;

    private ?MeetingView $view = null;

    public function __construct(
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly DatabaseMeetingService $databaseMeetingService,
        private readonly MeetingQueryService $meetingQueryService,
        private readonly MeetingPointRepository $meetingPointRepository,
        private readonly MeetingDocumentRepository $meetingDocumentRepository,
        private readonly MeetingActivityLogRepository $meetingActivityLogRepository,
        private readonly MeetingDocumentService $meetingDocumentService,
        private readonly MeetingMinutesService $meetingMinutesService,
        private readonly ReferenceDocumentRepository $referenceDocumentRepository,
        private readonly ReferenceDocumentService $referenceDocumentService,
        private readonly MeetingLocalDetailsService $meetingLocalDetailsService,
        private readonly VersionLabelSuggester $versionLabelSuggester,
    ) {
    }

    /**
     * Every library document with this meeting's selection of it (or null when not selected), for the reference tab.
     *
     * @return list<array{document: ReferenceDocument, selection: ?MeetingReferenceSelection}>
     */
    public function getReferenceOptions(): array
    {
        $selectionsByDocumentId = [];
        foreach ($this->getView()->references as $selection) {
            $selectionsByDocumentId[(int) $selection->getReferenceDocument()->getId()] = $selection;
        }

        $options = [];
        foreach ($this->referenceDocumentRepository->findAllWithUsageCounts() as [$document]) {
            $options[] = [
                'document' => $document,
                'selection' => $selectionsByDocumentId[(int) $document->getId()] ?? null,
            ];
        }

        return $options;
    }

    /**
     * The ledger's side of this meeting, for the decisions tab: what was decided, in the language being read, and
     * the numbers a new decision would get, which the projection does not carry. Null when the ledger does not
     * know the meeting, in which case there is nothing to record a decision against either.
     */
    public function getLedgerView(): ?LedgerMeetingView
    {
        $this->assertAccess();

        return $this->databaseMeetingService->getMeetingView(
            MeetingTypes::tryFromSearch(strtoupper($this->type)),
            $this->number,
        );
    }

    public function getView(): MeetingView
    {
        $this->assertAccess();

        if (null !== $this->view) {
            return $this->view;
        }

        $view = $this->meetingQueryService->getMeetingView(
            MeetingTypes::tryFromSearch(strtoupper($this->type)),
            $this->number,
        );

        if (null === $view) {
            throw new NotFoundHttpException('Meeting not found.');
        }

        $this->view = $view;
        $this->seedEdits($view);

        return $view;
    }

    /**
     * Fill the pending-edit arrays with what is on screen.
     *
     * The inline inputs bind to a path inside one of them, `pointEdits.<id>.title` and the like, and a model path
     * is only valid to the client if every level of it already exists among the component's props. Left empty, as
     * they are before anything has been edited and again after {@see syncEdits()} clears them, the first keystroke
     * in any of those inputs fails with "Invalid model name".
     *
     * Only missing keys are filled, never ones already there: on a re-render the arrays come back carrying what the
     * reader typed, and seeding over that would undo it. {@see syncEdits()} compares against what is stored before
     * writing anything, so seeding the current values does not turn into a write of every row.
     */
    private function seedEdits(MeetingView $view): void
    {
        foreach ($view->points as $pointView) {
            $id = (string) $pointView->point->getId();
            $this->pointEdits[$id]['number'] ??= $pointView->point->getNumber();
            $this->pointEdits[$id]['title'] ??= $pointView->point->getTitle();

            foreach ($pointView->documents as $document) {
                $this->documentEdits[(string) $document->getId()]['name'] ??= $document->getName();
            }
        }

        foreach ($view->meetingLevelDocuments as $document) {
            $this->documentEdits[(string) $document->getId()]['name'] ??= $document->getName();
        }

        foreach ($view->references as $selection) {
            $version = $selection->getPinnedVersion();

            if (null === $version) {
                continue;
            }

            $this->pins[(string) $selection->getReferenceDocument()->getId()] ??= (string) $version->getId();
        }

        $this->details['startTime'] ??= $view->localDetails?->getStartTime()?->format('H:i') ?? '';
        $this->details['location'] ??= $view->localDetails?->getLocation() ?? '';
    }

    public function getReadiness(): MeetingReadiness
    {
        return $this->meetingQueryService->getReadiness($this->getView());
    }

    /**
     * @return list<MeetingActivityLog>
     */
    public function getActivity(): array
    {
        return $this->meetingActivityLogRepository->findRecentForMeeting($this->getView()->meeting);
    }

    public function suggestLabel(?string $previousLabel): string
    {
        return $this->versionLabelSuggester->suggest($previousLabel);
    }

    /**
     * Applies the pending inline edits delivered with this request, before rendering.
     */
    #[PreReRender]
    public function syncEdits(): void
    {
        $this->assertAccess();

        $applied = false;

        foreach ($this->pointEdits as $id => $fields) {
            $point = $this->point((int) $id);

            if (null === $point) {
                continue;
            }

            $number = trim(strval($fields['number'] ?? $point->getNumber()));
            $title = trim(strval($fields['title'] ?? $point->getTitle()));

            // These arrays are seeded with what is on screen so the inputs have a model path to bind to, so most of
            // what is in them on any given save is not an edit at all.
            if (
                $number === $point->getNumber()
                && $title === $point->getTitle()
            ) {
                continue;
            }

            $this->meetingDocumentService->updatePoint(
                $point,
                $number,
                $title,
                $this->actor(),
            );
            $applied = true;
        }

        foreach ($this->documentEdits as $id => $fields) {
            $document = $this->document((int) $id);
            $name = trim(strval($fields['name'] ?? ''));

            if (
                null === $document
                || '' === $name
                || $name === $document->getName()
            ) {
                continue;
            }

            $this->meetingDocumentService->renameDocument(
                $document,
                $name,
                $this->actor(),
            );
            $applied = true;
        }

        foreach ($this->pins as $id => $versionId) {
            $document = $this->referenceDocumentRepository->find((int) $id);

            if (null === $document) {
                continue;
            }

            $version = null;
            foreach ($document->getVersions() as $candidate) {
                if ($candidate->getId() === (int) $versionId) {
                    $version = $candidate;
                    break;
                }
            }

            if (
                null === $version
                || $version === $this->selectionFor($document)?->getPinnedVersion()
            ) {
                continue;
            }

            $this->referenceDocumentService->pinVersion(
                $this->meeting(),
                $document,
                $version,
                $this->actor(),
            );
            $applied = true;
        }

        if ([] !== $this->details) {
            $existing = $this->meeting()->getLocalDetails();
            $startTime = strval($this->details['startTime'] ?? $existing?->getStartTime()?->format('H:i') ?? '');
            $location = strval($this->details['location'] ?? $existing?->getLocation() ?? '');

            if (
                $startTime !== ($existing?->getStartTime()?->format('H:i') ?? '')
                || $location !== ($existing?->getLocation() ?? '')
            ) {
                $this->meetingLocalDetailsService->updateDetails(
                    $this->meeting(),
                    $startTime,
                    $location,
                    $this->actor(),
                );
                $applied = true;
            }
        }

        $this->pointEdits = [];
        $this->documentEdits = [];
        $this->pins = [];
        $this->details = [];

        if (!$applied) {
            return;
        }

        $this->markSaved();
    }

    #[LiveAction]
    public function toggleReference(#[LiveArg]
    int $id,): void
    {
        $this->assertAccess();

        $document = $this->referenceDocumentRepository->find($id);
        if (null === $document) {
            return;
        }

        $this->referenceDocumentService->toggleSelection(
            $this->meeting(),
            $document,
            $this->actor(),
        );
        $this->markSaved();
    }

    #[LiveAction]
    public function carryOver(): void
    {
        $this->assertAccess();

        $this->referenceDocumentService->carryOverSelection(
            $this->meeting(),
            $this->actor(),
        );
        $this->markSaved();
    }

    #[LiveAction]
    public function addPoint(): void
    {
        $this->assertAccess();

        $this->meetingDocumentService->createPoint(
            $this->getView()->meeting,
            '',
            '',
            $this->actor(),
        );
        $this->markSaved();
    }

    #[LiveAction]
    public function deletePoint(#[LiveArg]
    int $id,): void
    {
        $this->assertAccess();

        $point = $this->point($id);
        if (null === $point) {
            return;
        }

        $this->meetingDocumentService->deletePoint(
            $point,
            $this->actor(),
        );
        $this->markSaved();
    }

    #[LiveAction]
    public function deleteDocument(#[LiveArg]
    int $id,): void
    {
        $this->assertAccess();

        $document = $this->document($id);
        if (null === $document) {
            return;
        }

        $this->meetingDocumentService->deleteDocument(
            $document,
            $this->actor(),
        );
        $this->markSaved();
    }

    /**
     * Remove one decision of this meeting from the ledger, along with everything it recorded.
     *
     * The ledger turns down a decision that a later one builds on, which is the whole reason this is worth an answer
     * rather than a redirect: the reader stays on the meeting and is told why nothing was removed.
     */
    #[LiveAction]
    public function deleteDecision(
        #[LiveArg]
        int $point,
        #[LiveArg]
        int $number,
    ): void {
        $this->assertAccess();

        try {
            $deleted = $this->databaseMeetingService->deleteDecision(
                MeetingTypes::tryFromSearch(strtoupper($this->type)),
                $this->number,
                $point,
                $number,
            );
        } catch (DecisionStillReferenced) {
            $this->feedback = new TranslatableMessage('Other decisions still refer to this one.')
                ->trans($this->translator);

            return;
        } catch (AnnulmentNotPossible) {
            $this->feedback = new TranslatableMessage(
                'Removing this annulment would restore a decision that later decisions have since overtaken.',
            )->trans($this->translator);

            return;
        }

        // Two secretaries can hold this open at once, and the second one to answer it deletes nothing. Reporting
        // success either way would have them believe they removed something they did not.
        if (!$deleted) {
            $this->feedback = new TranslatableMessage('This decision no longer exists.')->trans($this->translator);

            return;
        }

        $this->markSaved();
    }

    #[LiveAction]
    public function deleteMinutes(): void
    {
        $this->assertAccess();

        $this->meetingMinutesService->deleteMinutes(
            $this->getView()->meeting,
            $this->actor(),
        );
        $this->markSaved();
    }

    /**
     * @param array<array-key, int|string> $orderedIds
     */
    #[LiveAction]
    public function reorderPoints(#[LiveArg]
    array $orderedIds,): void
    {
        $this->assertAccess();

        $this->meetingDocumentService->reorderPoints(
            $this->getView()->meeting,
            $this->normaliseIds($orderedIds),
            $this->actor(),
        );
        $this->markSaved();
    }

    /**
     * @param array<array-key, int|string> $orderedIds
     */
    #[LiveAction]
    public function reorderDocuments(
        #[LiveArg]
        array $orderedIds,
        #[LiveArg]
        ?int $pointId = null,
    ): void {
        $this->assertAccess();

        $point = null;
        if (null !== $pointId) {
            $point = $this->point($pointId);

            if (null === $point) {
                return;
            }
        }

        $this->meetingDocumentService->reorderDocuments(
            $this->getView()->meeting,
            $point,
            $this->normaliseIds($orderedIds),
            $this->actor(),
        );
        $this->markSaved();
    }

    /**
     * This meeting's selection of a library document, if it has one.
     */
    private function selectionFor(ReferenceDocument $document): ?MeetingReferenceSelection
    {
        foreach ($this->getView()->references as $selection) {
            if ($selection->getReferenceDocument()->getId() === $document->getId()) {
                return $selection;
            }
        }

        return null;
    }

    private function point(int $id): ?MeetingPoint
    {
        $point = $this->meetingPointRepository->find($id);

        if ($point?->getMeeting() !== $this->meeting()) {
            return null;
        }

        return $point;
    }

    private function document(int $id): ?MeetingDocument
    {
        $document = $this->meetingDocumentRepository->find($id);

        if ($document?->getMeeting() !== $this->meeting()) {
            return null;
        }

        return $document;
    }

    private function meeting(): Meeting
    {
        return $this->getView()->meeting;
    }

    private function markSaved(): void
    {
        $this->view = null;
        $this->savedAt = new DateTime()->format('H:i');
    }

    private function actor(): User
    {
        $user = $this->security->getUser();
        assert($user instanceof User);

        return $user;
    }

    private function assertAccess(): void
    {
        if (
            $this->security->isGranted(UserRoles::DatabaseAdmin->value)
            && $this->security->isGranted(SudoVoter::ATTRIBUTE)
        ) {
            return;
        }

        throw new AccessDeniedException();
    }

    /**
     * @param array<array-key, int|string> $ids
     *
     * @return list<int>
     */
    private function normaliseIds(array $ids): array
    {
        return array_values(array_map(
            static fn (int|string $id): int => (int) $id,
            $ids,
        ));
    }
}
