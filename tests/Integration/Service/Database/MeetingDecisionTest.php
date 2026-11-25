<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Application\Enums\AppLanguages;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting as MeetingModel;
use App\Entity\Database\SubDecision\Other;
use App\Exception\Database\AnnulmentNotPossible;
use App\Exception\Database\DecisionStillReferenced;
use App\Service\Database\Meeting;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_map;
use function count;
use function in_array;
use function sprintf;

/**
 * Reading a meeting back, recording and removing its decisions, and the lookups the decision screens use.
 */
#[CoversClass(Meeting::class)]
final class MeetingDecisionTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    private Meeting $meetingService;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = self::getContainer()->get(EntityManagerInterface::class);
        $this->meetingService = self::getContainer()->get(Meeting::class);
        $this->build = new LedgerBuilder($this->ledger);
    }

    public function testAnUnknownMeetingHasNoView(): void
    {
        self::assertNull($this->meetingService->getMeetingView(
            MeetingTypes::BV,
            999_999,
        ));
    }

    public function testTheMeetingViewListsEveryDecisionInPointOrderAndSaysWhatAnnulledIt(): void
    {
        $meeting = $this->build->meeting();
        $first = $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een taart te kopen.',
            'The board decides to buy a cake.',
        );
        $this->build->approveBudget(
            $meeting,
            '2026-01-01',
        );
        $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit de taart op te eten.',
            'The board decides to eat the cake.',
        );

        $annulment = $this->build->annul(
            $this->build->meeting(date: '2026-09-01'),
            $first->decision,
        );

        $this->ledger->clear();

        $view = $this->meetingService->getMeetingView(
            $meeting->type,
            $meeting->getNumber(),
        );
        self::assertNotNull($view);
        self::assertSame(
            MeetingTypes::BV,
            $view->meeting->type,
        );

        $points = array_map(
            static fn ($row): int => $row->point,
            $view->decisions,
        );
        self::assertSame(
            [
                1,
                2,
                3,
            ],
            $points,
        );
        self::assertSame(
            [
                1 => 2,
                2 => 2,
                3 => 2,
            ],
            $view->nextDecisionNumbers,
        );

        self::assertNotSame(
            '',
            $view->decisions[0]->content,
        );
        self::assertNotSame(
            '',
            $view->decisions[1]->content,
        );
        self::assertNotSame(
            '',
            $view->decisions[2]->content,
        );

        // The annulled decision is still shown: the register records that it was taken, and then taken back.
        self::assertNotNull($view->decisions[0]->annulment);
        self::assertSame(
            $annulment->decision->getMeetingNumber(),
            $view->decisions[0]->annulment->meetingNumber,
        );
        self::assertSame(
            $annulment->decision->point,
            $view->decisions[0]->annulment->point,
        );
        self::assertNull($view->decisions[1]->annulment);
        self::assertStringContainsString(
            'cake',
            $view->decisions[0]->content,
        );
        self::assertStringContainsString(
            'Begroting',
            $view->decisions[1]->content,
        );
        self::assertStringContainsString(
            'eat the cake',
            $view->decisions[2]->content,
        );
    }

    public function testTheDecisionOptionsAreTheBoardAndKeysThatStand(): void
    {
        $meeting = $this->build->meeting(date: '-1 month');
        $member = $this->build->member();
        $installation = $this->build->installBoard(
            $meeting,
            $member,
        );
        $granting = $this->build->grantKey(
            $meeting,
            $member,
        );

        $options = $this->meetingService->getDecisionOptions();

        self::assertTrue(in_array(
            $installation,
            $options->boardInstallations,
            true,
        ));
        self::assertTrue(in_array(
            $installation,
            $options->releasableBoardInstallations,
            true,
        ));
        self::assertTrue(in_array(
            $granting,
            $options->keyGrants,
            true,
        ));
    }

    public function testRecordingADecisionReturnsWhatIsShownBackToTheSecretary(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een taart te kopen.',
        );

        $recorded = $this->meetingService->recordDecision($other->decision);

        self::assertSame(
            $other->decision->getHash(),
            $recorded->hash,
        );
        self::assertSame(
            MeetingTypes::BV->value,
            $recorded->meetingType,
        );
        self::assertSame(
            $meeting->getNumber(),
            $recorded->meetingNumber,
        );
        self::assertCount(
            count(AppLanguages::cases()),
            $recorded->contents,
        );
        self::assertSame(
            [],
            $recorded->warnings,
        );
        self::assertNotSame(
            '',
            $recorded->copyContent,
        );

        $reconstructed = $this->meetingService->buildRecordedDecision($other->decision);
        self::assertSame(
            $recorded->hash,
            $reconstructed->hash,
        );
        self::assertSame(
            $recorded->contents,
            $reconstructed->contents,
        );
        self::assertSame(
            [],
            $reconstructed->warnings,
        );
    }

    public function testRecordingAnAnnulmentOfSomethingAlreadyAnnulledIsRefused(): void
    {
        $target = $this->build->foundOrgan($this->build->meeting());
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2026-09-01'),
            $target->decision,
        );

        $this->ledger->clear();

        // Reloaded so that the inverse side of the annulment is read from the database rather than from memory.
        $annulmentDecision = $this->meetingService->getDecision(
            $annulment->decision->getMeetingType(),
            $annulment->decision->getMeetingNumber(),
            $annulment->decision->point,
            $annulment->decision->number,
        );
        self::assertNotNull($annulmentDecision);
        $meeting = $annulmentDecision->meeting;

        $countBefore = $meeting->getDecisions()->count();

        try {
            $this->meetingService->recordDecision($annulmentDecision);
            self::fail('Expected the annulment of an annulled decision to be refused.');
        } catch (AnnulmentNotPossible $exception) {
            self::assertStringContainsString(
                'already been annulled',
                $exception->getMessage(),
            );
        }

        // The rejected decision is taken out of the meeting again, so it is not written by the next flush.
        self::assertCount(
            $countBefore - 1,
            $meeting->getDecisions(),
        );
    }

    public function testDeletingAnUnknownDecisionIsNotAChange(): void
    {
        self::assertFalse($this->meetingService->deleteDecision(
            MeetingTypes::BV,
            999_999,
            1,
            1,
        ));
    }

    public function testDeletingADecisionThatSomethingElseRefersToIsRefused(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan($meeting);
        $this->build->install(
            $this->build->meeting(date: '2026-09-01'),
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );

        $this->expectException(DecisionStillReferenced::class);
        $this->expectExceptionMessageIsOrContains('still referred to by another decision');

        $this->meetingService->deleteDecision(
            $foundation->getMeetingType(),
            $foundation->getMeetingNumber(),
            $foundation->getDecisionPoint(),
            $foundation->getDecisionNumber(),
        );
    }

    public function testDeletingAnAnnulmentThatWouldRestoreAnOvertakenDecisionIsRefused(): void
    {
        $meeting = $this->build->meeting();
        $installation = $this->build->install(
            $meeting,
            $this->build->foundOrgan($meeting),
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $discharge = $this->build->discharge(
            $this->build->meeting(date: '2026-09-01'),
            $installation,
        );
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2026-10-01'),
            $discharge->decision,
        );
        $this->build->discharge(
            $this->build->meeting(date: '2026-11-01'),
            $installation,
        );

        $this->expectException(AnnulmentNotPossible::class);

        $this->meetingService->deleteDecision(
            $annulment->decision->getMeetingType(),
            $annulment->decision->getMeetingNumber(),
            $annulment->decision->point,
            $annulment->decision->number,
        );
    }

    public function testLinkingAndUnlinkingAVirtualCounterpartCanMiss(): void
    {
        $board = $this->build->meeting(MeetingTypes::BV);
        $original = $this->build->decision($board);
        $virtual = $this->build->meeting(MeetingTypes::VIRT);
        $this->ledger->flush();

        self::assertFalse($this->meetingService->linkVirtualCounterpart(
            $board->type,
            $board->getNumber(),
            $original->point,
            $original->number,
            $virtual->type,
            $virtual->getNumber(),
            404,
            404,
        ));

        self::assertFalse($this->meetingService->unlinkVirtualCounterpart(
            $virtual->type,
            $virtual->getNumber(),
            404,
            404,
        ));
    }

    public function testExportSplitsDecisionsOverTheSectionsOfTheDecisionList(): void
    {
        $meeting = $this->build->meeting();
        $this->build->approveBudget(
            $meeting,
            '2026-01-01',
        );
        $this->build->foundOrgan($meeting);
        $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een taart te kopen.',
        );

        $export = $this->meetingService->exportDecisions([
            sprintf(
                '%s-%d',
                $meeting->type->value,
                $meeting->getNumber(),
            ),
        ]);

        self::assertCount(
            1,
            $export->financial,
        );
        self::assertCount(
            1,
            $export->install,
        );
        self::assertCount(
            1,
            $export->other,
        );

        self::assertSame(
            $meeting->date->format('Y-m-d'),
            $export->financial[0]->date->format('Y-m-d'),
        );
        self::assertStringContainsString(
            'Begroting',
            $export->financial[0]->content,
        );
    }

    public function testOrgansAreFoundByTheirNameAndComposition(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'QQQ',
            'Quantumbestuur',
        );
        $member = $this->build->member();
        $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Chair,
        );

        $identifiers = [
            $foundation->getMeetingType(),
            $foundation->getMeetingNumber(),
            $foundation->getDecisionPoint(),
            $foundation->getDecisionNumber(),
            $foundation->sequence,
        ];

        // Reloaded so that the organ's references reflect what was installed after it was founded.
        $this->ledger->clear();

        $found = $this->meetingService->findFoundation(...$identifiers);
        self::assertNotNull($found);
        self::assertSame(
            'QQQ',
            $found->abbr,
        );

        $organs = $this->meetingService->findOrgans('Quantum');
        self::assertContains(
            $found->getHash(),
            array_map(
                static fn ($organ): string => $organ->getHash(),
                $organs,
            ),
        );

        $asArray = $this->meetingService->searchOrgans('Quantumbestuur');
        self::assertNotSame(
            [],
            $asArray,
        );
        self::assertSame(
            'QQQ',
            $asArray[0]['abbr'],
        );

        $info = $this->meetingService->getOrganInfo(...$identifiers);
        self::assertNotNull($info);
        self::assertSame(
            'QQQ',
            $info['abbr'],
        );
        self::assertCount(
            1,
            $info['members'],
        );
        self::assertSame(
            $member->lidnr,
            $info['members'][0]['member']['lidnr'],
        );
        self::assertSame(
            InstallationFunctions::Chair,
            $info['members'][0]['installations'][0]['function'],
        );

        self::assertNull($this->meetingService->getOrganInfo(
            MeetingTypes::BV,
            999_999,
            1,
            1,
            1,
        ));
    }

    public function testDecisionAndMeetingSearchUseTheirReferences(): void
    {
        $meeting = $this->build->meeting();
        $decision = $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een taart te kopen.',
        );

        $reference = sprintf(
            '%s %d.%d.%d',
            $meeting->type->value,
            $meeting->getNumber(),
            $decision->decision->point,
            $decision->decision->number,
        );

        $decisions = $this->meetingService->searchDecisions($reference);
        self::assertContains(
            $reference,
            array_map(
                static fn (array $row): string => sprintf(
                    '%s %d.%d.%d',
                    $row['meeting_type']->value,
                    $row['meeting_number'],
                    $row['decision_point'],
                    $row['decision_number'],
                ),
                $decisions,
            ),
        );

        $meetings = $this->meetingService->searchMeetings((string) $meeting->getNumber());
        self::assertContains(
            [
                MeetingTypes::BV,
                $meeting->getNumber(),
            ],
            array_map(
                static fn (array $row): array => [
                    $row['meeting_type'],
                    $row['meeting_number'],
                ],
                $meetings,
            ),
        );
    }

    public function testAMeetingIsFoundByItsTypeAndNumberAndOnlyCreatedOnce(): void
    {
        $meeting = $this->build->meeting();

        $found = $this->meetingService->getMeeting(
            $meeting->type,
            $meeting->getNumber(),
        );
        self::assertNotNull($found);
        self::assertSame(
            $meeting->getNumber(),
            $found->getNumber(),
        );

        self::assertNull($this->meetingService->getMeeting(
            MeetingTypes::BV,
            999_999,
        ));

        // The form builds a fresh entity either way, so a number already in the ledger must be refused rather than
        // written twice.
        $duplicate = new MeetingModel();
        $duplicate->type = $meeting->type;
        $duplicate->setNumber($meeting->getNumber());
        $duplicate->date = new DateTimeImmutable('2026-08-20');
        self::assertFalse($this->meetingService->createMeeting($duplicate));

        $fresh = new MeetingModel();
        $fresh->type = MeetingTypes::BV;
        $fresh->setNumber(99_999);
        $fresh->date = new DateTimeImmutable('2026-08-20');
        self::assertTrue($this->meetingService->createMeeting($fresh));

        self::assertNotNull($this->meetingService->getMeeting(
            MeetingTypes::BV,
            99_999,
        ));
    }

    public function testADecisionIsFoundByThePositionItWasEnteredAt(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een taart te kopen.',
        );

        self::assertTrue($this->meetingService->decisionExists(
            $meeting->type,
            $meeting->getNumber(),
            $other->decision->point,
            $other->decision->number,
        ));
        self::assertFalse($this->meetingService->decisionExists(
            $meeting->type,
            $meeting->getNumber(),
            99,
            99,
        ));
    }

    public function testUntranslatedDecisionsAreAddressedByTheirPosition(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Het bestuur besluit een taart te kopen.',
        );

        self::assertGreaterThanOrEqual(
            1,
            $this->meetingService->countUntranslatedDecisions(),
        );

        $found = $this->meetingService->getUntranslatedDecision(
            $meeting->type,
            $meeting->getNumber(),
            $other->decision->point,
            $other->decision->number,
            $other->sequence,
        );
        self::assertInstanceOf(
            Other::class,
            $found,
        );
        self::assertSame(
            $other->contentNL,
            $found->contentNL,
        );

        self::assertNull($this->meetingService->getUntranslatedDecision(
            MeetingTypes::BV,
            999_999,
            1,
            1,
            1,
        ));
    }
}
