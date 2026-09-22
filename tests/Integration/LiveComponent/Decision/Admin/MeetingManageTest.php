<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision\Admin;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingMinutes;
use App\Entity\Decision\MeetingPoint;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\User\User;
use App\Security\User\SudoMode;
use App\Tests\Integration\DatabaseTestCase;
use App\Twig\Components\Decision\Admin\MeetingManage;
use App\ViewModel\Decision\MeetingPointView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_reverse;
use function count;
use function explode;
use function html_entity_decode;
use function is_array;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_starts_with;

/**
 * Exercises the meeting management component as the framework does (the real instance with its real services) after
 * authenticating on the token storage, mirroring the sign-up overview test: the live HTTP endpoint is not viable
 * because the session guard force-logs-out synthetic sessions.
 */
final class MeetingManageTest extends DatabaseTestCase
{
    public function testAddPointAppendsAnEmptyPoint(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        $before = $component->getView()->points;
        $component->addPoint();

        $points = $component->getView()->points;
        self::assertCount(
            count($before) + 1,
            $points,
        );
        $added = $points[count($before)]->point;
        self::assertSame(
            '',
            $added->number,
        );
        self::assertNotNull($component->savedAt);
    }

    public function testSyncEditsAppliesPendingPointAndDocumentEdits(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        $point = $this->point('2');
        $document = $component->getView()->points[0]->documents[0];

        $component->pointEdits = [
            (string) $point->id => [
                'number' => '9',
                'title' => 'Renumbered',
            ],
        ];
        $component->documentEdits = [
            (string) $document->id => ['name' => 'Agenda (final)'],
        ];
        $component->syncEdits();

        self::assertSame(
            '9',
            $point->number,
        );
        self::assertSame(
            'Renumbered',
            $point->title,
        );
        self::assertSame(
            'Agenda (final)',
            $document->name,
        );
        // The pending edit is cleared and replaced by the current values of the point.
        self::assertSame(
            [
                'number' => '9',
                'title' => 'Renumbered',
            ],
            $component->pointEdits[(string) $point->id],
        );
        self::assertNotNull($component->savedAt);
    }

    public function testDeletePointMovesItsDocumentsToTheMeetingLevelGroup(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        $point = $this->point('2');
        $component->deletePoint((int) $point->id);

        $view = $component->getView();
        $names = array_map(
            static fn (MeetingDocument $document) => $document->name,
            $view->meetingLevelDocuments,
        );
        self::assertContains(
            'Agenda',
            $names,
        );
        self::assertCount(
            3,
            $view->points,
        );
    }

    public function testDeleteDocumentRemovesItWithItsVersions(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        $document = $component->getView()->meetingLevelDocuments[0];
        $component->deleteDocument((int) $document->id);

        self::assertSame(
            [],
            $component->getView()->meetingLevelDocuments,
        );
    }

    public function testReorderPointsPersistsTheNewOrder(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        $ids = array_map(
            static fn (MeetingPointView $pointView) => (int) $pointView->point->id,
            $component->getView()->points,
        );
        $component->reorderPoints(array_reverse($ids));

        self::assertSame(
            array_reverse($ids),
            array_map(
                static fn (MeetingPointView $pointView) => (int) $pointView->point->id,
                $component->getView()->points,
            ),
        );
    }

    public function testDeleteMinutesRemovesTheMinutes(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        self::assertNotNull($component->getView()->minutes);
        $component->deleteMinutes();

        self::assertNull($component->getView()->minutes);
    }

    public function testToggleReferenceSelectsAndDeselectsALibraryDocument(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        $definitions = $this->referenceDocument('Financial Definition List');
        self::assertCount(
            1,
            $component->getView()->references,
        );

        $component->toggleReference((int) $definitions->id);
        $references = $component->getView()->references;
        self::assertCount(
            2,
            $references,
        );

        // A fresh selection pins the latest version explicitly; nothing ever follows the library implicitly.
        foreach ($references as $selection) {
            if ($selection->referenceDocument !== $definitions) {
                continue;
            }

            self::assertSame(
                $definitions->getLatestVersion(),
                $selection->pinnedVersion,
            );
        }

        $component->toggleReference((int) $definitions->id);
        self::assertCount(
            1,
            $component->getView()->references,
        );
    }

    public function testCarryOverCopiesTheSelectionOfThePreviousMeeting(): void
    {
        $this->authenticate();
        $component = $this->manageFor(3);

        self::assertSame(
            [],
            $component->getView()->references,
        );

        $component->carryOver();

        $references = $component->getView()->references;
        self::assertCount(
            2,
            $references,
        );
        $names = array_map(
            static fn ($selection) => $selection->referenceDocument->name,
            $references,
        );
        self::assertContains(
            'Scenarios and Procedures',
            $names,
        );
        self::assertContains(
            'Financial Definition List',
            $names,
        );
    }

    public function testPendingPinsAreAppliedBeforeRendering(): void
    {
        $this->authenticate();
        $component = $this->manageFor(1);

        $scenarios = $this->referenceDocument('Scenarios and Procedures');
        $original = $scenarios->getVersions()->first();
        self::assertNotFalse($original);

        $component->pins = [(string) $scenarios->id => (string) $original->id];
        $component->syncEdits();

        $selection = $component->getView()->references[0];
        self::assertSame(
            'v3.0',
            $selection->pinnedVersion->versionLabel,
        );
    }

    public function testDetailsEditsPersistTheTimeAndPlace(): void
    {
        $this->authenticate();
        $component = $this->manageFor(1);

        self::assertNull($component->getView()->localDetails);

        $component->details = [
            'startTime' => '20:00',
            'location' => 'Auditorium 4',
        ];
        $component->syncEdits();

        $details = $component->getView()->localDetails;
        self::assertNotNull($details);
        self::assertSame(
            '20:00',
            $details->startTime?->format('H:i'),
        );
        self::assertSame(
            'Auditorium 4',
            $details->location,
        );
        self::assertNotNull($component->savedAt);
    }

    public function testTheBoardKeepsTheAgendaAndItsDocuments(): void
    {
        // Keeping a meeting is the board's job, so a board seat is all its agenda and its documents require.
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->manageFor();

        $before = $component->getView()->points;
        $component->addPoint();

        self::assertCount(
            count($before) + 1,
            $component->getView()->points,
        );
    }

    public function testWritingToTheLedgerRequiresTheDatabaseAdministratorRole(): void
    {
        // What was decided in a meeting is the register's record; a board seat on its own is not enough for it.
        $this->authenticate(['ROLE_BOARD']);
        $component = $this->manageFor();

        $this->expectException(AccessDeniedException::class);
        $component->deleteDecision(
            1,
            1,
        );
    }

    public function testActionsRequireMoreThanAnActiveMemberRole(): void
    {
        $this->authenticate(['ROLE_ACTIVE_MEMBER']);
        $component = $this->manageFor();

        $this->expectException(AccessDeniedException::class);
        $component->addPoint();
    }

    public function testActionsRequireSudoMode(): void
    {
        $this->authenticate(
            ['ROLE_BOARD'],
            sudo: false,
        );
        $component = $this->manageFor();

        $this->expectException(AccessDeniedException::class);
        $component->addPoint();
    }

    /**
     * @param list<string> $roles
     */
    private function authenticate(
        array $roles = [
            'ROLE_BOARD',
            'ROLE_DATABASE_ADMIN',
        ],
        bool $sudo = true,
    ): void {
        $user = $this->entityManager->getRepository(User::class)->find(8000);
        self::assertInstanceOf(
            User::class,
            $user,
        );

        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $roles,
        ));

        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $request->cookies->set(
            $session->getName(),
            $session->getId(),
        );
        self::getContainer()->get('request_stack')->push($request);

        if (!$sudo) {
            return;
        }

        self::getContainer()->get(SudoMode::class)->grant();
    }

    /**
     * The inline inputs bind to a path inside one of the pending-edit arrays, and the client rejects a model path
     * whose every level does not already exist among the props. They are dehydrated before the template runs, so
     * this renders the component rather than reading the arrays off it: seeding them during the render is what
     * "Invalid model name" was.
     */
    public function testEveryInlineInputHasAModelPathAmongTheProps(): void
    {
        $this->authenticate();

        $html = $this->renderManage();
        $props = $this->propsOf($html);
        $paths = $this->modelPathsOf($html);

        self::assertContains(
            'details.startTime',
            $paths,
        );
        self::assertNotEmpty(array_filter(
            $paths,
            static fn (string $path) => str_starts_with(
                $path,
                'pointEdits.',
            ),
        ));

        foreach ($paths as $path) {
            self::assertNotNull(
                $this->resolve(
                    $props,
                    $path,
                ),
                sprintf(
                    'The model path "%s" is not among the props.',
                    $path,
                ),
            );
        }
    }

    /**
     * A point added during the visit is only on screen from the re-render that follows, which is the one render the
     * initial seeding does not cover.
     */
    public function testAPointAddedDuringTheVisitIsSeededToo(): void
    {
        $this->authenticate();
        $component = $this->manageFor();

        $component->addPoint();
        $component->syncEdits();

        $points = $component->getView()->points;
        $added = $points[count($points) - 1]->point;

        self::assertArrayHasKey(
            (string) $added->id,
            $component->pointEdits,
        );
    }

    /**
     * Seeding the arrays with what is on screen must not turn every save into a write of every row.
     */
    public function testSyncEditsWritesNothingWhenNothingChanged(): void
    {
        $this->authenticate();
        $component = $this->manageFor();
        $component->getView();

        $component->syncEdits();

        self::assertNull(
            $component->savedAt,
            'Nothing was edited, so nothing was saved.',
        );
    }

    /**
     * The component as the page renders it, which is the only way to read the props sent to the client.
     */
    private function renderManage(): string
    {
        return self::getContainer()->get('twig')->createTemplate(
            "{{ component('Decision:Admin:MeetingManage', {type: type, number: number}) }}",
        )->render([
            'type' => MeetingTypes::ALV,
            'number' => $this->completeGmmNumber(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function propsOf(string $html): array
    {
        self::assertSame(
            1,
            preg_match(
                '/data-live-props-value="([^"]*)"/',
                $html,
                $matches,
            ),
        );

        $props = json_decode(
            html_entity_decode($matches[1]),
            true,
        );
        self::assertIsArray($props);

        return $props;
    }

    /**
     * The model of every input that binds to one, without the modifiers that may precede it.
     *
     * @return list<string>
     */
    private function modelPathsOf(string $html): array
    {
        preg_match_all(
            '/data-model="(?:[^"]*\|)?([^"]*)"/',
            $html,
            $matches,
        );

        return $matches[1];
    }

    /**
     * Resolves a model path the way the client does: walk the props level by level, and return null when one of
     * those levels is missing.
     *
     * @param array<string, mixed> $props
     */
    private function resolve(
        array $props,
        string $path,
    ): mixed {
        $current = $props;
        $parts = explode(
            '.',
            $path,
        );

        foreach ($parts as $part) {
            if (
                !is_array($current)
                || !array_key_exists(
                    $part,
                    $current,
                )
            ) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }

    /**
     * The complete GMM by default; positive offsets move forward through the sequentially numbered GMMs (+1 the
     * processing one, +2 the soonest upcoming, +3 the one after).
     */
    private function manageFor(int $offset = 0): MeetingManage
    {
        $component = self::getContainer()->get(MeetingManage::class);
        $component->type = MeetingTypes::ALV;
        $component->number = $this->completeGmmNumber() + $offset;

        return $component;
    }

    private function completeGmmNumber(): int
    {
        $minutes = $this->entityManager->getRepository(MeetingMinutes::class)->findAll();
        self::assertCount(
            1,
            $minutes,
        );

        return $minutes[0]->meeting->number;
    }

    private function referenceDocument(string $name): ReferenceDocument
    {
        $document = $this->entityManager->getRepository(ReferenceDocument::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(
            ReferenceDocument::class,
            $document,
        );

        return $document;
    }

    private function point(string $number): MeetingPoint
    {
        $meeting = $this->entityManager->find(
            Meeting::class,
            [
                'type' => MeetingTypes::ALV,
                'number' => $this->completeGmmNumber(),
            ],
        );
        self::assertNotNull($meeting);

        $point = $this->entityManager->getRepository(MeetingPoint::class)->findOneBy([
            'meeting' => $meeting,
            'number' => $number,
        ]);
        self::assertInstanceOf(
            MeetingPoint::class,
            $point,
        );

        return $point;
    }
}
