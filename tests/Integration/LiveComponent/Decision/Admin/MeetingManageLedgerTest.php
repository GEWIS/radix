<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent\Decision\Admin;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\Decision\MeetingPoint;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\User\User;
use App\Exception\Database\AnnulmentNotPossible;
use App\Exception\Database\DecisionNamesDeletedMember;
use App\Exception\Database\DecisionStillReferenced;
use App\Repository\Decision\MeetingActivityLogRepository;
use App\Repository\Decision\MeetingDocumentRepository;
use App\Repository\Decision\MeetingPointRepository;
use App\Repository\Decision\ReferenceDocumentRepository;
use App\Security\User\SudoMode;
use App\Service\Database\Meeting as DatabaseMeetingService;
use App\Service\Decision\MeetingDocumentService;
use App\Service\Decision\MeetingLocalDetailsService;
use App\Service\Decision\MeetingMinutesService;
use App\Service\Decision\MeetingQueryService;
use App\Service\Decision\ReferenceDocumentService;
use App\Service\Decision\VersionLabelSuggester;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use App\Twig\Components\Decision\Admin\MeetingManage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_map;
use function array_reverse;
use function count;
use function in_array;

/**
 * The parts of the meeting administration that write to the register: removing a decision, tying a decision to its
 * virtual counterpart, and the no-change paths of the inline edits. Built meetings keep the coordinates clear of the
 * seed's, and the projection listeners run on every flush the builder makes.
 */
#[AllowMockObjectsWithoutExpectations]
final class MeetingManageLedgerTest extends DatabaseTestCase
{
    public function testAMeetingThatDoesNotExistHasNoManagementView(): void
    {
        $this->authenticate();

        $component = $this->manageFor(99999);

        $this->expectException(NotFoundHttpException::class);
        $component->getView();
    }

    public function testTheLedgerSideOfTheMeetingIsRead(): void
    {
        $this->authenticate();

        $component = $this->manageFor($this->completeGmmNumber());

        self::assertNotNull($component->getLedgerView());
    }

    public function testPendingEditsThatChangeNothingAreNotWritten(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());
        $view = $component->getView();

        $document = $view->points[0]->documents[0];

        $component->pointEdits = [
            '999999' => [
                'number' => '7',
                'title' => 'Not a point of this meeting',
            ],
            (string) $view->points[0]->point->id => [
                'number' => $view->points[0]->point->number,
                'title' => $view->points[0]->point->title,
            ],
        ];
        $component->documentEdits = [
            '999999' => ['name' => 'Not a document of this meeting'],
            (string) $document->id => ['name' => $document->name],
        ];
        $component->pins = [
            '999999' => '1',
            (string) $this->referenceDocumentId($view->references[0]->referenceDocument) => '999999',
        ];

        $component->syncEdits();

        self::assertNull(
            $component->savedAt,
            'Nothing was edited, so nothing was saved.',
        );
    }

    /**
     * A pin can name a library document this meeting has not selected; there is no selection to pin, so the reference
     * list stays as it is.
     */
    public function testPinningADocumentThatIsNotSelectedLeavesTheSelectionAlone(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());
        $view = $component->getView();

        $selectedIds = array_map(
            static fn ($selection): int => (int) $selection->referenceDocument->id,
            $view->references,
        );

        $document = null;
        foreach (self::getContainer()->get(ReferenceDocumentRepository::class)->findAll() as $candidate) {
            if (
                !in_array(
                    (int) $candidate->id,
                    $selectedIds,
                    true,
                )
            ) {
                $document = $candidate;

                break;
            }
        }

        self::assertInstanceOf(
            ReferenceDocument::class,
            $document,
        );

        $version = $document->getVersions()->first();
        self::assertNotFalse($version);

        $component->pins = [(string) $document->id => (string) $version->id];
        $component->syncEdits();

        self::assertNotNull($component->savedAt);
        self::assertCount(
            count($selectedIds),
            $component->getView()->references,
        );
    }

    public function testAnUnknownReferenceIsIgnoredByTheToggle(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->toggleReference(999999);

        self::assertNull($component->savedAt);
    }

    public function testDeletingAnUnknownPointOrDocumentDoesNothing(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->deletePoint(999999);
        $component->deleteDocument(999999);

        self::assertNull($component->savedAt);
    }

    public function testDocumentsCanBeReorderedWithinAPointAndTheMeeting(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());
        $view = $component->getView();

        $point = $view->points[1];
        $pointIds = array_map(
            static fn (MeetingDocument $document): int => (int) $document->id,
            $point->documents,
        );

        $component->reorderDocuments(
            array_reverse($pointIds),
            (int) $point->point->id,
        );
        self::assertNotNull($component->savedAt);

        $component->savedAt = null;
        $levelIds = array_map(
            static fn (MeetingDocument $document): int => (int) $document->id,
            $component->getView()->meetingLevelDocuments,
        );
        $component->reorderDocuments($levelIds);
        self::assertNotNull($component->savedAt);
    }

    public function testADocumentGroupCannotBeReorderedUnderAnotherMeetingsPoint(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->getView();
        $own = $component->getView()->meeting;
        $foreign = null;
        foreach ($this->entityManager->getRepository(MeetingPoint::class)->findAll() as $candidate) {
            if ($candidate->meeting !== $own) {
                $foreign = $candidate;

                break;
            }
        }

        self::assertInstanceOf(
            MeetingPoint::class,
            $foreign,
        );

        $component->reorderDocuments(
            [],
            (int) $foreign->id,
        );

        self::assertNull($component->savedAt);
    }

    /**
     * A decision of a built meeting is removed from the register, and the component reports the write.
     */
    public function testADecisionOfTheMeetingIsDeleted(): void
    {
        $this->authenticate();
        $builder = $this->builder();
        $meeting = $builder->meeting(MeetingTypes::BV);
        $builder->decideFreely(
            $meeting,
            'Removed by the test',
        );
        $this->entityManager->clear();

        $component = $this->manageFor(
            $meeting->getNumber(),
            MeetingTypes::BV,
        );
        $component->deleteDecision(
            1,
            1,
        );

        self::assertNotNull($component->savedAt);
    }

    public function testDeletingADecisionThatIsNoLongerThereSaysSo(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->deleteDecision(
            99,
            99,
        );

        self::assertNull($component->savedAt);
        self::assertSame(
            'This decision no longer exists.',
            $component->feedback,
        );
    }

    public function testADecisionStillReferredToIsReported(): void
    {
        $this->authenticate();

        $component = $this->withLedgerService(
            $this->refusingDatabaseMeetingService(new DecisionStillReferenced()),
        );
        $component->deleteDecision(
            1,
            1,
        );

        self::assertSame(
            'Other decisions still refer to this one.',
            $component->feedback,
        );
        self::assertNull($component->savedAt);
    }

    public function testADecisionNamingADeletedMemberIsReported(): void
    {
        $this->authenticate();

        $component = $this->withLedgerService(
            $this->refusingDatabaseMeetingService(new DecisionNamesDeletedMember()),
        );
        $component->deleteDecision(
            1,
            1,
        );

        self::assertStringContainsString(
            'names a member who has been deleted',
            (string) $component->feedback,
        );
        self::assertNull($component->savedAt);
    }

    public function testRemovingAnAnnulmentThatWouldRestoreAnOvertakenDecisionIsReported(): void
    {
        $this->authenticate();

        $component = $this->withLedgerService(
            $this->refusingDatabaseMeetingService(new AnnulmentNotPossible()),
        );
        $component->deleteDecision(
            1,
            1,
        );

        self::assertStringContainsString(
            'Removing this annulment',
            (string) $component->feedback,
        );
        self::assertNull($component->savedAt);
    }

    public function testAVirtualDecisionCanBeLinkedAndUnlinked(): void
    {
        $this->authenticate();
        $builder = $this->builder();
        $meeting = $builder->meeting(MeetingTypes::BV);
        $builder->decideFreely(
            $meeting,
            'The ordinary decision',
        );
        $virtual = $builder->meeting(MeetingTypes::VIRT);
        $builder->decideFreely(
            $virtual,
            'The virtual decision',
        );
        $this->entityManager->clear();

        $component = $this->manageFor(
            $meeting->getNumber(),
            MeetingTypes::BV,
        );

        $component->linkVirtualCounterpart(
            1,
            1,
            MeetingTypes::VIRT->value,
            $virtual->getNumber(),
            1,
            1,
        );
        self::assertNotNull($component->savedAt);

        $component->savedAt = null;
        $component->unlinkVirtualCounterpart(
            MeetingTypes::VIRT->value,
            $virtual->getNumber(),
            1,
            1,
        );
        self::assertNotNull($component->savedAt);
    }

    public function testLinkingToADecisionThatIsNoLongerThereSaysSo(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->linkVirtualCounterpart(
            1,
            1,
            MeetingTypes::VIRT->value,
            99999,
            1,
            1,
        );

        self::assertSame(
            'This decision no longer exists.',
            $component->feedback,
        );
        self::assertNull($component->savedAt);
    }

    public function testLinkingUnderAnUnknownMeetingTypeIsRefused(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->linkVirtualCounterpart(
            1,
            1,
            'not-a-meeting-type',
            1,
            1,
            1,
        );
        self::assertSame(
            'These two decisions cannot be one another\'s counterpart.',
            $component->feedback,
        );

        $component->feedback = null;
        $component->unlinkVirtualCounterpart(
            'not-a-meeting-type',
            1,
            1,
            1,
        );
        self::assertSame(
            'These two decisions cannot be one another\'s counterpart.',
            $component->feedback,
        );
    }

    public function testADecisionOfAnotherMeetingTypeIsNotAVirtualCounterpart(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->linkVirtualCounterpart(
            1,
            1,
            MeetingTypes::ALV->value,
            1,
            1,
            1,
        );

        self::assertSame(
            'These two decisions cannot be one another\'s counterpart.',
            $component->feedback,
        );
        self::assertNull($component->savedAt);
    }

    public function testUnlinkingADecisionThatIsNoLongerThereSaysSo(): void
    {
        $this->authenticate();
        $component = $this->manageFor($this->completeGmmNumber());

        $component->unlinkVirtualCounterpart(
            MeetingTypes::VIRT->value,
            99999,
            1,
            1,
        );

        self::assertSame(
            'This decision no longer exists.',
            $component->feedback,
        );
        self::assertNull($component->savedAt);
    }

    private function withLedgerService(DatabaseMeetingService $service): MeetingManage
    {
        $component = new MeetingManage(
            security: self::getContainer()->get(Security::class),
            translator: self::getContainer()->get(TranslatorInterface::class),
            databaseMeetingService: $service,
            meetingQueryService: self::getContainer()->get(MeetingQueryService::class),
            meetingPointRepository: self::getContainer()->get(MeetingPointRepository::class),
            meetingDocumentRepository: self::getContainer()->get(MeetingDocumentRepository::class),
            meetingActivityLogRepository: self::getContainer()->get(MeetingActivityLogRepository::class),
            meetingDocumentService: self::getContainer()->get(MeetingDocumentService::class),
            meetingMinutesService: self::getContainer()->get(MeetingMinutesService::class),
            referenceDocumentRepository: self::getContainer()->get(ReferenceDocumentRepository::class),
            referenceDocumentService: self::getContainer()->get(ReferenceDocumentService::class),
            meetingLocalDetailsService: self::getContainer()->get(MeetingLocalDetailsService::class),
            versionLabelSuggester: self::getContainer()->get(VersionLabelSuggester::class),
        );
        $component->type = MeetingTypes::ALV;
        $component->number = $this->completeGmmNumber();

        return $component;
    }

    private function refusingDatabaseMeetingService(Throwable $exception): DatabaseMeetingService
    {
        $service = $this->createMock(DatabaseMeetingService::class);
        $service->method('deleteDecision')->willThrowException($exception);

        return $service;
    }

    private function referenceDocumentId(ReferenceDocument $document): int
    {
        self::assertNotNull($document->id);

        return $document->id;
    }

    private function builder(): LedgerBuilder
    {
        $ledger = self::getContainer()->get('doctrine')->getManager('database');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        return new LedgerBuilder($ledger);
    }

    /**
     * @param list<string> $roles
     */
    private function authenticate(
        array $roles = [
            'ROLE_BOARD',
            'ROLE_DATABASE_ADMIN',
        ],
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
        self::getContainer()->get(SudoMode::class)->grant();
    }

    private function manageFor(
        int $number,
        MeetingTypes $type = MeetingTypes::ALV,
    ): MeetingManage {
        $component = self::getContainer()->get(MeetingManage::class);
        $component->type = $type;
        $component->number = $number;

        return $component;
    }

    private function completeGmmNumber(): int
    {
        $repository = $this->entityManager->getRepository(MeetingMinutesVersion::class);
        $version = $repository->findOneBy([]);
        self::assertInstanceOf(
            MeetingMinutesVersion::class,
            $version,
        );

        return $version->minutes->meeting->number;
    }
}
