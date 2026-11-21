<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Decision as DatabaseDecision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting as DatabaseMeeting;
use App\Entity\Database\SubDecision as DatabaseSubDecision;
use App\Entity\Decision\Decision as DecisionDecision;
use App\Entity\Decision\Meeting as DecisionMeeting;
use App\Entity\Decision\Member as DecisionMember;
use App\Entity\Decision\Organ as DecisionOrgan;
use App\Entity\Decision\OrganMember as DecisionOrganMember;
use App\Entity\Decision\SubDecision as DecisionSubDecision;
use App\Entity\Decision\SubDecision\Discharge as DecisionDischarge;
use App\Entity\Decision\SubDecision\Foundation as DecisionFoundation;
use App\Entity\Decision\SubDecision\Installation as DecisionInstallation;
use App\Entity\Decision\SubDecision\Reappointment as DecisionReappointment;
use App\Repository\Database\MeetingRepository;
use App\Service\Decision\MeetingService;
use App\Service\Decision\ProjectionState;
use App\Service\Decision\SubDecisionService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

use function count;
use function sprintf;

/**
 * The service that replays the ledger into the projection, meeting by meeting. Every one of its steps is what the
 * ledger's update listeners call while the register is written, so it is also what a full rebuild runs.
 */
#[CoversClass(MeetingService::class)]
final class MeetingServiceTest extends DatabaseTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $ledger;
    private EntityManagerInterface $projection;
    private LedgerBuilder $build;
    private MeetingService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->ledger = $ledger;
        $this->projection = $this->entityManager;
        $this->build = new LedgerBuilder($ledger);
        $this->service = self::getContainer()->get(MeetingService::class);
    }

    public function testGenerateMeetingCreatesTheProjectedMeeting(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::ALV,
            '2026-07-15',
        );

        // The listener wrote it already; take it away so the create branch is the one under test.
        $projected = $this->projectedMeeting($meeting);
        self::assertNotNull($projected);
        $this->projection->remove($projected);
        $this->projection->flush();

        $this->service->generateMeeting($meeting);
        $this->projection->flush();

        $projected = $this->projectedMeeting($meeting);

        self::assertNotNull($projected);
        self::assertSame(
            MeetingTypes::ALV,
            $projected->type,
        );
        self::assertSame(
            $meeting->getNumber(),
            $projected->number,
        );
        self::assertSame(
            '2026-07-15',
            $projected->date->format('Y-m-d'),
        );
    }

    public function testGeneratingAMeetingASecondTimeDoesNotDuplicateIt(): void
    {
        $meeting = $this->build->meeting();

        $this->service->generateMeeting($meeting);
        $this->service->generateMeeting($meeting);
        $this->projection->flush();

        self::assertSame(
            1,
            $this->projection->getRepository(DecisionMeeting::class)->count([
                'type' => $meeting->type,
                'number' => $meeting->getNumber(),
            ]),
        );
    }

    /**
     * A meeting's type and number identify it, but its date may be corrected after the fact.
     */
    public function testGenerateMeetingKeepsTheProjectedDateLevelWithTheLedger(): void
    {
        $meeting = $this->build->meeting(
            MeetingTypes::BV,
            '2026-06-01',
        );

        $meeting->date = new DateTimeImmutable('2026-06-02');
        $this->service->generateMeeting($meeting);
        $this->projection->flush();
        $this->projection->clear();

        $projected = $this->projectedMeeting($meeting);

        self::assertNotNull($projected);
        self::assertSame(
            '2026-06-02',
            $projected->date->format('Y-m-d'),
        );
    }

    public function testGenerateDecisionCreatesTheProjectedDecision(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Er wordt een taart gekocht.',
            'A cake is bought.',
        );

        $projected = $this->projectedDecision($other->decision);
        self::assertNotNull($projected);
        $this->projection->remove($projected);
        $this->projection->flush();

        $this->service->generateDecision($other->decision);
        $this->projection->flush();

        $projected = $this->projectedDecision($other->decision);

        self::assertNotNull($projected);
        self::assertSame(
            'Er wordt een taart gekocht.',
            $projected->contentNL,
        );
        self::assertSame(
            'A cake is bought.',
            $projected->contentEN,
        );
    }

    /**
     * A second pass over a decision must write what it reads now, not add a second decision beside the first.
     */
    public function testGenerateDecisionRefreshesTheContentInsteadOfDuplicatingTheDecision(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Het oude besluit.',
            'The old decision.',
        );

        // Changed in memory only: the service reads the ledger entity, so this is what a rewrite looks like.
        $other->contentNL = 'Het nieuwe besluit.';
        $other->contentEN = 'The new decision.';

        $this->service->generateDecision($other->decision);
        $this->projection->flush();
        $this->projection->clear();

        $projected = $this->projectedDecision($other->decision);

        self::assertNotNull($projected);
        self::assertSame(
            'Het nieuwe besluit.',
            $projected->contentNL,
        );
        self::assertSame(
            'The new decision.',
            $projected->contentEN,
        );
        self::assertSame(
            1,
            $this->projection->getRepository(DecisionDecision::class)->count([
                'meeting_type' => $meeting->type,
                'meeting_number' => $meeting->getNumber(),
            ]),
        );
    }

    /**
     * Annulling does more than set a flag: what the decision brought about is undone at the point the annulment is
     * replayed, so a body it founded never exists.
     */
    public function testAnAnnulledDecisionIsMarkedAndItsEffectsAreReverted(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ANN',
            'Annuleringscommissie',
        );
        self::assertNotNull($this->organOf('ANN'));

        $annulment = $this->build->annul(
            $this->build->meeting(date: '2026-09-01'),
            $foundation->decision,
        );
        $this->projection->clear();

        $target = $this->projectedDecision($foundation->decision);
        self::assertNotNull($target);
        self::assertTrue($target->isAnnulled());
        self::assertNull($this->organOf('ANN'));

        $annulmentDecision = $this->projectedDecision($annulment->decision);
        self::assertNotNull($annulmentDecision);
        self::assertStringContainsString(
            'nietig verklaard',
            $annulmentDecision->contentNL,
        );
    }

    /**
     * The other direction: a decision may be deleted from the ledger, and what it undid is put back.
     */
    public function testDeletingTheAnnulmentRestoresTheDecisionItAnnulled(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'UNA',
            'Herstelcommissie',
        );
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2026-09-01'),
            $foundation->decision,
        );
        self::assertNull($this->organOf('UNA'));

        $this->ledger->remove($annulment->decision);
        $this->ledger->flush();
        $this->projection->clear();

        $target = $this->projectedDecision($foundation->decision);

        self::assertNotNull($target);
        self::assertFalse($target->isAnnulled());
        self::assertNotNull($this->organOf('UNA'));
    }

    public function testDeletingADecisionRemovesItAndEverythingDerivedFromIt(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'DEL',
            'Verwijdercommissie',
        );

        $this->ledger->remove($foundation->decision);
        $this->ledger->flush();
        $this->projection->clear();

        self::assertNull($this->projectedDecision($foundation->decision));
        self::assertNull($this->organOf('DEL'));
    }

    /**
     * A subdecision deleted on its own takes its organ membership with it, and drops out of the body's history.
     */
    public function testDeleteSubDecisionRemovesAnInstallationAndItsOrganMembership(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'DSD',
            'Subbesluitcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Chair,
        );

        $projectedInstallation = $this->projectedSubDecision($installation);
        self::assertInstanceOf(
            DecisionInstallation::class,
            $projectedInstallation,
        );

        $organ = $this->organOf('DSD');
        self::assertNotNull($organ);
        self::assertCount(
            1,
            $organ->getMembers(),
        );

        $this->service->deleteSubDecision($projectedInstallation);
        $this->projection->flush();
        $this->projection->clear();

        $organ = $this->organOf('DSD');

        self::assertNotNull($organ);
        // The body outlives the installation; only the membership goes.
        self::assertCount(
            0,
            $organ->getMembers(),
        );
        self::assertNull($this->projectedSubDecision($installation));
    }

    /**
     * Deleting a foundation takes the body with it. (An organ with members cannot be reached this way in the
     * projection: every installation still references the foundation it was installed in, so those have to go first,
     * which is what {@see self::testDeleteSubDecisionRemovesAnInstallationAndItsOrganMembership()} covers.)
     */
    public function testDeleteSubDecisionOfAFoundationTakesTheOrganWithIt(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'FDS',
            'Opheffingscommissie',
        );

        $projectedFoundation = $this->projectedSubDecision($foundation);
        self::assertInstanceOf(
            DecisionFoundation::class,
            $projectedFoundation,
        );

        $this->service->deleteSubDecision($projectedFoundation);
        $this->projection->flush();
        $this->projection->clear();

        self::assertNull($this->organOf('FDS'));
        self::assertNull($this->projectedSubDecision($foundation));
    }

    /**
     * Projecting a subdecision transfers what it decided into the projection's own class tree. The organ and the
     * organ membership are then derived from it, which is what makes the body and its members visible.
     */
    public function testGenerateSubDecisionTransfersTheSubdecisionAndItsReferences(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'GSD',
            'Subbesluitcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Chair,
        );
        $reappointment = $this->build->reappoint(
            $this->build->meeting(date: '2027-02-01'),
            $installation,
        );
        $discharge = $this->build->discharge(
            $this->build->meeting(date: '2027-03-01'),
            $installation,
        );

        $projectedFoundation = $this->service->generateSubDecision($foundation);

        self::assertInstanceOf(
            DecisionFoundation::class,
            $projectedFoundation,
        );
        self::assertSame(
            'GSD',
            $projectedFoundation->abbr,
        );
        self::assertSame(
            'Subbesluitcommissie',
            $projectedFoundation->name,
        );
        // The same subdecision means the same row, not a second one.
        self::assertSame(
            $projectedFoundation,
            $this->service->generateSubDecision($foundation),
        );

        $projectedInstallation = $this->service->generateSubDecision($installation);

        self::assertInstanceOf(
            DecisionInstallation::class,
            $projectedInstallation,
        );
        self::assertSame(
            InstallationFunctions::Chair,
            $projectedInstallation->function,
        );
        self::assertSame(
            $installation->getMember()->lidnr,
            $projectedInstallation->getMember()->lidnr,
        );

        self::getContainer()->get(SubDecisionService::class)->generateRelated($projectedInstallation);
        $this->projection->flush();

        self::assertNotNull(
            $this->projection->getRepository(DecisionOrganMember::class)
                ->findOneBy(['installation' => $projectedInstallation]),
        );

        $projectedReappointment = $this->service->generateSubDecision($reappointment);
        self::assertInstanceOf(
            DecisionReappointment::class,
            $projectedReappointment,
        );
        self::assertSame(
            $projectedInstallation,
            $projectedReappointment->installation,
        );

        $projectedDischarge = $this->service->generateSubDecision($discharge);
        self::assertInstanceOf(
            DecisionDischarge::class,
            $projectedDischarge,
        );
        self::assertSame(
            $projectedInstallation,
            $projectedDischarge->installation,
        );
    }

    public function testFindMemberReturnsTheProjectedMember(): void
    {
        $member = $this->build->member();

        $projected = $this->service->findMember($member);

        self::assertSame(
            $member->lidnr,
            $projected->lidnr,
        );
        // Looked up through the same manager, so a second call cannot return a different row.
        self::assertSame(
            $projected,
            $this->service->findMember($member),
        );
    }

    public function testFindMemberThrowsWhenTheMemberIsNotProjected(): void
    {
        $member = $this->build->member();

        $projected = $this->projection->getRepository(DecisionMember::class)->find($member->lidnr);
        self::assertNotNull($projected);
        $this->projection->remove($projected);
        $this->projection->flush();
        $this->projection->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            sprintf(
                'Member %d does not exist in the projection',
                $member->lidnr,
            ),
        );

        $this->service->findMember($member);
    }

    /**
     * A subdecision written after its decision leaves the decision with the text it had before, so it is refreshed on
     * its own.
     */
    public function testRefreshDecisionContentRewritesTheDecisionText(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Eerste tekst.',
            'First text.',
        );

        $other->contentNL = 'Tweede tekst.';
        $other->contentEN = 'Second text.';

        $this->service->refreshDecisionContent($other->decision);
        $this->projection->flush();
        $this->projection->clear();

        $projected = $this->projectedDecision($other->decision);

        self::assertNotNull($projected);
        self::assertSame(
            'Tweede tekst.',
            $projected->contentNL,
        );
        self::assertSame(
            'Second text.',
            $projected->contentEN,
        );
    }

    public function testRefreshDecisionContentLeavesADecisionTheProjectionDoesNotKnow(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Onbekend besluit.',
            'Unknown decision.',
        );

        $projected = $this->projectedDecision($other->decision);
        self::assertNotNull($projected);
        $this->projection->remove($projected);
        $this->projection->flush();

        // Silently, because the caller is a full pass that is about to write it in full.
        $this->service->refreshDecisionContent($other->decision);
        $this->projection->flush();

        self::assertNull($this->projectedDecision($other->decision));
    }

    /**
     * A full replay is what `app:decision:generate` runs: every meeting oldest first, reporting progress as it goes.
     * A virtual decision that sorts before the decision it repeats can only be linked once both have been replayed.
     */
    public function testGenerateReplaysTheLedgerAndLinksVirtualCounterparts(): void
    {
        $virtual = $this->build->meeting(
            MeetingTypes::VIRT,
            '2026-01-01',
        );
        $repeat = $this->build->decision($virtual);
        $board = $this->build->meeting();
        $original = $this->build->decision($board);
        $this->ledger->flush();
        $repeat->counterpart = $original;
        $this->ledger->flush();

        $progress = [];
        $this->service->generate(
            static function (int $current, int $total) use (&$progress): void {
                $progress[] = [
                    $current,
                    $total,
                ];
            },
        );

        $total = count(
            self::getContainer()->get(MeetingRepository::class)->findAllWithDecisionCount(true),
        );

        self::assertNotEmpty($progress);
        self::assertSame(
            [
                $total,
                $total,
            ],
            $progress[count($progress) - 1],
        );

        $this->projection->clear();

        $projectedRepeat = $this->projectedDecision($repeat);
        $projectedOriginal = $this->projectedDecision($original);

        self::assertNotNull($projectedRepeat);
        self::assertNotNull($projectedOriginal);
        self::assertNotNull($this->projectedMeeting($board));
        self::assertSame(
            $projectedOriginal,
            $projectedRepeat->counterpart,
        );
    }

    /**
     * One broken decision must not stop the meeting: the failure is reported by mail, and a decision that failed
     * halfway stays half-written, which is what the mail describes.
     */
    public function testAMeetingWithABrokenDecisionIsReportedByMail(): void
    {
        // Build the ledger without the projection following it, so the member the installation names is in the
        // ledger but not in the projection when the meeting is replayed.
        $state = self::getContainer()->get(ProjectionState::class);
        $state->disable();

        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ERR',
            'Foutcommissie',
        );
        $member = $this->build->member();
        $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );

        $state->enable();

        $this->service->generateMeeting($meeting);

        // Not flushed: the decision that failed was half-built into the projection, which is exactly why the failure is
        // reported rather than asserted on. What is under test here is that the meeting's other decision did not stop
        // the failure from being reported.
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertInstanceOf(
            Email::class,
            $email,
        );
        self::assertSame(
            'Database fout',
            $email->getSubject(),
        );
        self::assertEmailTextBodyContains(
            $email,
            sprintf(
                'Member %d does not exist in the projection',
                $member->lidnr,
            ),
        );
    }

    public function testSendDecisionExceptionMailNamesTheDecisionAndTheError(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Een besluit.',
            'A decision.',
        );

        $this->service->sendDecisionExceptionMail(
            new RuntimeException('Iets ging mis'),
            $other->decision,
        );

        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertInstanceOf(
            Email::class,
            $email,
        );
        self::assertSame(
            'Database fout',
            $email->getSubject(),
        );
        self::assertEmailTextBodyContains(
            $email,
            'Iets ging mis',
        );
        self::assertEmailTextBodyContains(
            $email,
            sprintf(
                '%s %d.%d.%d.',
                $meeting->type->value,
                $meeting->getNumber(),
                $other->decision->point,
                $other->decision->number,
            ),
        );
    }

    private function projectedMeeting(DatabaseMeeting $meeting): ?DecisionMeeting
    {
        return $this->projection->getRepository(DecisionMeeting::class)->find([
            'type' => $meeting->type,
            'number' => $meeting->getNumber(),
        ]);
    }

    private function projectedDecision(DatabaseDecision $decision): ?DecisionDecision
    {
        return $this->projection->getRepository(DecisionDecision::class)->find([
            'meeting_type' => $decision->getMeetingType(),
            'meeting_number' => $decision->getMeetingNumber(),
            'point' => $decision->point,
            'number' => $decision->number,
        ]);
    }

    private function projectedSubDecision(DatabaseSubDecision $subDecision): ?DecisionSubDecision
    {
        return $this->projection->getRepository(DecisionSubDecision::class)->find([
            'meeting_type' => $subDecision->getMeetingType(),
            'meeting_number' => $subDecision->getMeetingNumber(),
            'decision_point' => $subDecision->getDecisionPoint(),
            'decision_number' => $subDecision->getDecisionNumber(),
            'sequence' => $subDecision->sequence,
        ]);
    }

    private function organOf(string $abbreviation): ?DecisionOrgan
    {
        return $this->projection->getRepository(DecisionOrgan::class)
            ->findOneBy(['abbr' => $abbreviation]);
    }
}
