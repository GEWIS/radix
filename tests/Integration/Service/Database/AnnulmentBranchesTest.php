<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Database;

use App\Entity\Database\Decision;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\SubDecision\Board\Discharge as BoardDischarge;
use App\Entity\Database\SubDecision\Board\Installation as BoardInstallation;
use App\Entity\Database\SubDecision\Foundation;
use App\Entity\Database\SubDecision\Installation;
use App\Entity\Database\SubDecision\Key\Withdrawal;
use App\Exception\Database\AnnulmentNotPossible;
use App\Service\Database\Annulment;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The remaining branches of the ledger rule: board members, siblings inside one decision, decisions that have already
 * been annulled, and the ordering that decides which decision came first.
 *
 * Every write is rolled back by dama/doctrine-test-bundle, so the seed these decisions are added to survives the run.
 */
#[CoversClass(Annulment::class)]
final class AnnulmentBranchesTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    private Annulment $annulment;

    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = self::getContainer()->get(EntityManagerInterface::class);
        $this->annulment = self::getContainer()->get(Annulment::class);
        $this->build = new LedgerBuilder($this->ledger);
    }

    public function testABoardInstallationThatWasDischargedCannotBeAnnulled(): void
    {
        $installation = $this->build->installBoard(
            $this->build->meeting(),
            $this->build->member(),
        );
        $this->boardDischarge(
            $this->build->meeting(date: '2027-01-01'),
            $installation,
        );

        try {
            $this->annulment->assertDecisionCanBeAnnulled($installation->decision);
            self::fail('Expected the installation to be protected by its discharge.');
        } catch (AnnulmentNotPossible $exception) {
            self::assertStringContainsString(
                'discharged after being installed',
                $exception->getMessage(),
            );
        }
    }

    public function testABoardReleaseThatWasDischargedCannotBeAnnulled(): void
    {
        $installation = $this->build->installBoard(
            $this->build->meeting(),
            $this->build->member(),
        );
        $release = $this->build->releaseBoard(
            $this->build->meeting(date: '2027-01-01'),
            $installation,
        );
        $this->boardDischarge(
            $this->build->meeting(date: '2027-02-01'),
            $installation,
        );

        try {
            $this->annulment->assertDecisionCanBeAnnulled($release->decision);
            self::fail('Expected the release to be protected by the discharge.');
        } catch (AnnulmentNotPossible $exception) {
            self::assertStringContainsString(
                'discharged after being released',
                $exception->getMessage(),
            );
        }
    }

    public function testAnOrganThatWasAbolishedTwiceCannotHaveTheFirstAbolitionAnnulled(): void
    {
        $foundation = $this->build->foundOrgan($this->build->meeting());
        $first = $this->build->abrogate(
            $this->build->meeting(date: '2027-01-01'),
            $foundation,
        );
        $this->build->abrogate(
            $this->build->meeting(date: '2027-02-01'),
            $foundation,
        );

        try {
            $this->annulment->assertDecisionCanBeAnnulled($first->decision);
            self::fail('Expected the second abolition to protect the first.');
        } catch (AnnulmentNotPossible $exception) {
            self::assertStringContainsString(
                'abolished by another decision',
                $exception->getMessage(),
            );
        }
    }

    public function testAWithdrawalThatWasMadeAgainProtectsTheFirstOne(): void
    {
        $granting = $this->build->grantKey(
            $this->build->meeting(),
            $this->build->member(),
        );
        $first = $this->build->withdrawKey(
            $this->build->meeting(date: '2026-10-01'),
            $granting,
            '+1 month',
        );
        $second = new Withdrawal();
        $second->granting = $granting;
        $second->withdrawnOn = new DateTimeImmutable('+2 months');
        $second->sequence = 1;
        $second->setDecision($this->build->decision($this->build->meeting(date: '2026-11-01')));
        $this->ledger->persist($second);
        $this->ledger->flush();

        try {
            $this->annulment->assertDecisionCanBeAnnulled($first->decision);
            self::fail('Expected the second withdrawal to protect the first.');
        } catch (AnnulmentNotPossible $exception) {
            self::assertStringContainsString(
                'withdrawn by another decision',
                $exception->getMessage(),
            );
        }
    }

    public function testAlreadyAnnulledDependentsDoNotCount(): void
    {
        $granting = $this->build->grantKey(
            $this->build->meeting(),
            $this->build->member(),
        );
        $withdrawal = $this->build->withdrawKey(
            $this->build->meeting(date: '2026-10-01'),
            $granting,
        );
        $this->build->annul(
            $this->build->meeting(date: '2026-11-01'),
            $withdrawal->decision,
        );

        self::assertSame(
            [],
            $this->annulment->assertDecisionCanBeAnnulled($granting->decision),
        );
    }

    public function testSiblingSubdecisionsOfTheSameDecisionDoNotCountAsDependents(): void
    {
        $meeting = $this->build->meeting();

        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->point = 1;
        $decision->number = 1;
        $this->ledger->persist($decision);

        $foundation = new Foundation();
        $foundation->abbr = 'SIB';
        $foundation->name = 'Siblingcommissie';
        $foundation->organType = OrganTypes::Committee;
        $foundation->sequence = 1;
        $foundation->setDecision($decision);
        $this->ledger->persist($foundation);

        $installation = new Installation();
        $installation->foundation = $foundation;
        $installation->setMember($this->build->member());
        $installation->function = InstallationFunctions::Member;
        $installation->sequence = 2;
        $installation->setDecision($decision);
        $this->ledger->persist($installation);
        $this->ledger->flush();

        self::assertSame(
            [],
            $this->annulment->assertDecisionCanBeAnnulled($decision),
        );
    }

    public function testAReappointmentThatWasExtendedAgainWarnsRatherThanRefuses(): void
    {
        $installation = $this->build->install(
            $this->build->meeting(),
            $this->build->foundOrgan($this->build->meeting()),
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $first = $this->build->reappoint(
            $this->build->meeting(date: '2027-01-01'),
            $installation,
        );
        $this->build->reappoint(
            $this->build->meeting(date: '2027-02-01'),
            $installation,
        );

        $warnings = $this->annulment->assertDecisionCanBeAnnulled($first->decision);

        self::assertCount(
            1,
            $warnings,
        );
        self::assertStringContainsString(
            'prolonged again afterwards',
            $warnings[0],
        );
    }

    public function testDeletingAnAnnulmentIsRefusedWhenWhatItRestoresWasDecidedAboutAgain(): void
    {
        $installation = $this->build->install(
            $this->build->meeting(),
            $this->build->foundOrgan($this->build->meeting()),
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $reappointment = $this->build->reappoint(
            $this->build->meeting(date: '2027-01-01'),
            $installation,
        );
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2027-02-01'),
            $reappointment->decision,
        );
        $this->build->discharge(
            $this->build->meeting(date: '2027-03-01'),
            $installation,
        );

        try {
            $this->annulment->assertAnnulmentCanBeDeleted($annulment);
            self::fail('Expected the later discharge to protect the annulment.');
        } catch (AnnulmentNotPossible $exception) {
            self::assertStringContainsString(
                'decided about again after this annulment',
                $exception->getMessage(),
            );
        }
    }

    public function testAnAnnulmentWhoseTargetHasNoDependentsCanBeDeleted(): void
    {
        $budget = $this->build->approveBudget(
            $this->build->meeting(),
            '2026-01-01',
        );
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2027-01-01'),
            $budget->decision,
        );

        $this->annulment->assertAnnulmentCanBeDeleted($annulment);

        self::assertSame(
            [],
            $this->annulment->assertDecisionCanBeAnnulled($annulment->target),
        );
    }

    public function testDeletingAnAnnulmentOfAReappointmentCarriesItsWarning(): void
    {
        $installation = $this->build->install(
            $this->build->meeting(),
            $this->build->foundOrgan($this->build->meeting()),
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $reappointment = $this->build->reappoint(
            $this->build->meeting(date: '2027-01-01'),
            $installation,
        );
        $this->build->discharge(
            $this->build->meeting(date: '2027-02-01'),
            $installation,
        );
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2027-03-01'),
            $reappointment->decision,
        );

        $this->annulment->assertAnnulmentCanBeDeleted($annulment);

        $warnings = $this->annulment->assertDecisionCanBeAnnulled($annulment->target);
        self::assertCount(
            1,
            $warnings,
        );
        self::assertStringContainsString(
            'discharged after being reappointed',
            $warnings[0],
        );
    }

    public function testOrderingFollowsDateTypeMeetingAndPosition(): void
    {
        $early = $this->build->decideFreely(
            $this->build->meeting(date: '2026-08-01'),
            'Vroeg.',
        );
        $late = $this->build->decideFreely(
            $this->build->meeting(date: '2026-09-01'),
            'Laat.',
        );

        self::assertTrue($this->annulment->isBefore($early->decision, $late->decision));
        self::assertFalse($this->annulment->isBefore($late->decision, $early->decision));

        // Same date, different meeting types: the type's own order settles it.
        $alv = $this->build->decideFreely(
            $this->build->meeting(
                MeetingTypes::ALV,
                '2026-10-01',
            ),
            'ALV.',
        );
        $bv = $this->build->decideFreely(
            $this->build->meeting(
                MeetingTypes::BV,
                '2026-10-01',
            ),
            'BV.',
        );
        self::assertTrue($this->annulment->isBefore($alv->decision, $bv->decision));

        // Same meeting: the point settles it.
        $meeting = $this->build->meeting(date: '2026-11-01');
        $firstPoint = $this->build->decideFreely(
            $meeting,
            'Eerste punt.',
        );
        $secondPoint = $this->build->decideFreely(
            $meeting,
            'Tweede punt.',
        );
        self::assertTrue($this->annulment->isBefore($firstPoint->decision, $secondPoint->decision));

        // Same point: the decision number settles it.
        $firstNumber = $this->decisionAt(
            $meeting,
            5,
            1,
        );
        $secondNumber = $this->decisionAt(
            $meeting,
            5,
            2,
        );
        self::assertTrue($this->annulment->isBefore($firstNumber, $secondNumber));
        self::assertFalse($this->annulment->isBefore($secondNumber, $firstNumber));
    }

    public function testIsAnnullingRecognisesAnAnnulmentAndNothingElse(): void
    {
        $budget = $this->build->approveBudget(
            $this->build->meeting(),
            '2026-01-01',
        );
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2027-01-01'),
            $budget->decision,
        );

        self::assertFalse($this->annulment->isAnnulling($budget->decision));
        self::assertTrue($this->annulment->isAnnulling($annulment->decision));
    }

    private function boardDischarge(
        Meeting $meeting,
        BoardInstallation $installation,
    ): BoardDischarge {
        $discharge = new BoardDischarge();
        $discharge->installation = $installation;
        $discharge->sequence = 1;
        $discharge->setDecision($this->build->decision($meeting));

        $this->ledger->persist($discharge);
        $this->ledger->flush();

        return $discharge;
    }

    private function decisionAt(
        Meeting $meeting,
        int $point,
        int $number,
    ): Decision {
        $decision = new Decision();
        $decision->setMeeting($meeting);
        $decision->point = $point;
        $decision->number = $number;

        $this->ledger->persist($decision);
        $this->ledger->flush();

        return $decision;
    }
}
