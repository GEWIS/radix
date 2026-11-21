<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Enums\BoardFunctions;
use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Meeting;
use App\Entity\Database\SubDecision as DatabaseSubDecision;
use App\Entity\Database\SubDecision\Board\Discharge as DatabaseBoardDischarge;
use App\Entity\Database\SubDecision\Board\Installation as DatabaseBoardInstallation;
use App\Entity\Decision\BoardMember;
use App\Entity\Decision\Keyholder;
use App\Entity\Decision\Organ;
use App\Entity\Decision\OrganMember;
use App\Entity\Decision\SubDecision as DecisionSubDecision;
use App\Entity\Decision\SubDecision\Abrogation as DecisionAbrogation;
use App\Entity\Decision\SubDecision\Annulment as DecisionAnnulment;
use App\Entity\Decision\SubDecision\Board\Discharge as DecisionBoardDischarge;
use App\Entity\Decision\SubDecision\Board\Installation as DecisionBoardInstallation;
use App\Entity\Decision\SubDecision\Board\Release as DecisionBoardRelease;
use App\Entity\Decision\SubDecision\Discharge as DecisionDischarge;
use App\Entity\Decision\SubDecision\Foundation as DecisionFoundation;
use App\Entity\Decision\SubDecision\Installation as DecisionInstallation;
use App\Entity\Decision\SubDecision\Key\Granting as DecisionKeyGranting;
use App\Entity\Decision\SubDecision\Key\Withdrawal as DecisionKeyWithdrawal;
use App\Entity\Decision\SubDecision\Reappointment as DecisionReappointment;
use App\Service\Decision\SubDecisionService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionClass;

use function in_array;

/**
 * Applying a subdecision to the projection, and undoing that again when it is annulled or deleted. Each kind of
 * subdecision derives a different row, which is why every one of them is exercised here rather than only the pattern.
 */
#[CoversClass(SubDecisionService::class)]
final class SubDecisionServiceTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;
    private EntityManagerInterface $projection;
    private LedgerBuilder $build;
    private SubDecisionService $service;

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
        $this->service = self::getContainer()->get(SubDecisionService::class);
    }

    public function testAFoundationDerivesTheOrganAndRevertingRemovesIt(): void
    {
        $foundation = $this->build->foundOrgan(
            $this->build->meeting(),
            'SDT',
            'Subbesluitcommissie',
        );

        $projectedFoundation = $this->projected($foundation);
        self::assertInstanceOf(
            DecisionFoundation::class,
            $projectedFoundation,
        );
        self::assertNotNull($this->organOf('SDT'));

        $this->service->revertRelated($projectedFoundation);
        $this->projection->flush();

        self::assertNull($this->organOf('SDT'));

        $this->service->generateRelated($projectedFoundation);
        $this->projection->flush();

        self::assertNotNull($this->organOf('SDT'));
    }

    public function testAnAbrogationDatesTheOrganAndRevertingUndoesIt(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDA',
            'Abrogatiecommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Chair,
        );
        $abrogation = $this->build->abrogate(
            $this->build->meeting(date: '2027-02-01'),
            $foundation,
        );

        $projectedAbrogation = $this->projected($abrogation);
        self::assertInstanceOf(
            DecisionAbrogation::class,
            $projectedAbrogation,
        );
        $organMember = $this->organMemberOf(
            $this->projected($installation),
        );
        self::assertNotNull($organMember);

        $this->service->revertRelated($projectedAbrogation);
        $this->projection->flush();
        $this->projection->clear();

        $organ = $this->organOf('SDA');

        self::assertNotNull($organ);
        self::assertNull($organ->abrogationDate);
        self::assertNull(
            $this->organMemberOf($this->projected($installation))?->dischargeDate,
        );

        $this->service->generateRelated($this->projected($abrogation));
        $this->projection->flush();
        $this->projection->clear();

        $organ = $this->organOf('SDA');

        self::assertNotNull($organ);
        self::assertSame(
            '2027-02-01',
            $organ->abrogationDate?->format('Y-m-d'),
        );
        self::assertSame(
            '2027-02-01',
            $this->organMemberOf($this->projected($installation))?->dischargeDate?->format('Y-m-d'),
        );
    }

    public function testAnInstallationDerivesTheOrganMemberAndRevertingRemovesIt(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDI',
            'Installatiecommissie',
        );
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Treasurer,
        );

        $projected = $this->projected($installation);
        self::assertInstanceOf(
            DecisionInstallation::class,
            $projected,
        );

        $organMember = $this->organMemberOf($projected);
        self::assertNotNull($organMember);
        self::assertSame(
            InstallationFunctions::Treasurer,
            $organMember->function,
        );

        $this->service->revertRelated($projected);
        $this->projection->flush();
        $this->projection->clear();

        $organ = $this->organOf('SDI');
        self::assertNotNull($organ);
        self::assertCount(
            0,
            $organ->getMembers(),
        );

        $this->service->generateRelated($this->projected($installation));
        $this->projection->flush();
        $this->projection->clear();

        $organ = $this->organOf('SDI');
        self::assertNotNull($organ);
        self::assertCount(
            1,
            $organ->getMembers(),
        );
        $organMember = $organ->getMembers()->first();
        self::assertInstanceOf(
            OrganMember::class,
            $organMember,
        );
        self::assertSame(
            $member->lidnr,
            $organMember->member->lidnr,
        );
    }

    public function testADischargeDatesTheOrganMemberAndRevertingUndoesIt(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDD',
            'Ontslagcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Secretary,
        );
        $discharge = $this->build->discharge(
            $this->build->meeting(date: '2027-03-01'),
            $installation,
        );

        $projectedDischarge = $this->projected($discharge);
        self::assertInstanceOf(
            DecisionDischarge::class,
            $projectedDischarge,
        );
        self::assertSame(
            '2027-03-01',
            $this->organMemberOf($this->projected($installation))?->dischargeDate?->format('Y-m-d'),
        );

        $this->service->revertRelated($projectedDischarge);
        $this->projection->flush();

        self::assertNull(
            $this->organMemberOf($this->projected($installation))->dischargeDate,
        );

        $this->service->generateRelated($projectedDischarge);
        $this->projection->flush();

        self::assertSame(
            '2027-03-01',
            $this->organMemberDischargedOn($this->projected($installation)),
        );
    }

    /**
     * A reappointment derives nothing of its own; it is part of the installation's record and reverting takes it off.
     */
    public function testRevertingAReappointmentTakesItOffTheInstallation(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDR',
            'Herbenoemingscommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $reappointment = $this->build->reappoint(
            $this->build->meeting(date: '2027-04-01'),
            $installation,
        );

        $projectedReappointment = $this->projected($reappointment);
        self::assertInstanceOf(
            DecisionReappointment::class,
            $projectedReappointment,
        );

        $this->projection->clear();

        $projectedInstallation = $this->projected($installation);
        self::assertInstanceOf(
            DecisionInstallation::class,
            $projectedInstallation,
        );
        self::assertCount(
            1,
            $projectedInstallation->getReappointments(),
        );

        $this->service->revertRelated($this->projected($reappointment));
        $this->projection->flush();

        self::assertCount(
            0,
            $projectedInstallation->getReappointments(),
        );
    }

    public function testABoardInstallationDerivesTheBoardMemberAndRevertingRemovesIt(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $installation = $this->build->installBoard(
            $meeting,
            $member,
            BoardFunctions::Treasurer,
            '2026-09-01',
        );

        $projected = $this->projected($installation);
        self::assertInstanceOf(
            DecisionBoardInstallation::class,
            $projected,
        );

        $boardMember = $this->boardMemberOf($projected);
        self::assertNotNull($boardMember);
        self::assertSame(
            BoardFunctions::Treasurer,
            $boardMember->function,
        );
        self::assertSame(
            '2026-09-01',
            $boardMember->installDate->format('Y-m-d'),
        );

        $this->service->revertRelated($projected);
        $this->projection->flush();

        self::assertNull($this->boardMemberOf($projected));

        $this->service->generateRelated($projected);
        $this->projection->flush();

        self::assertNotNull($this->boardMemberOf($projected));
    }

    public function testABoardReleaseDatesTheBoardMemberAndRevertingUndoesIt(): void
    {
        $installation = $this->build->installBoard(
            $this->build->meeting(),
            $this->build->member(),
        );
        $release = $this->build->releaseBoard(
            $this->build->meeting(date: '2027-09-01'),
            $installation,
            '2027-08-01',
        );

        $projectedRelease = $this->projected($release);
        self::assertInstanceOf(
            DecisionBoardRelease::class,
            $projectedRelease,
        );
        $projectedInstallation = $this->projected($installation);
        self::assertSame(
            '2027-08-01',
            $this->boardMemberOf($projectedInstallation)?->releaseDate?->format('Y-m-d'),
        );

        $this->service->revertRelated($projectedRelease);
        $this->projection->flush();

        self::assertNull(
            $this->boardMemberOf($projectedInstallation)->releaseDate,
        );

        $this->service->generateRelated($projectedRelease);
        $this->projection->flush();

        self::assertSame(
            '2027-08-01',
            $this->boardMemberReleasedOn($projectedInstallation),
        );
    }

    public function testABoardDischargeDatesTheBoardMemberAndRevertingUndoesIt(): void
    {
        $installation = $this->build->installBoard(
            $this->build->meeting(),
            $this->build->member(),
        );
        $discharge = $this->boardDischarge(
            $this->build->meeting(date: '2027-10-01'),
            $installation,
        );

        $projectedDischarge = $this->projected($discharge);
        self::assertInstanceOf(
            DecisionBoardDischarge::class,
            $projectedDischarge,
        );
        $projectedInstallation = $this->projected($installation);
        self::assertSame(
            '2027-10-01',
            $this->boardMemberOf($projectedInstallation)?->dischargeDate?->format('Y-m-d'),
        );

        $this->service->revertRelated($projectedDischarge);
        $this->projection->flush();

        self::assertNull(
            $this->boardMemberOf($projectedInstallation)->dischargeDate,
        );

        $this->service->generateRelated($projectedDischarge);
        $this->projection->flush();

        self::assertSame(
            '2027-10-01',
            $this->boardMemberDischargedOn($projectedInstallation),
        );
    }

    public function testAKeyGrantingDerivesTheKeyholderAndRevertingRemovesIt(): void
    {
        $member = $this->build->member();
        $granting = $this->build->grantKey(
            $this->build->meeting(),
            $member,
            '+3 months',
        );

        $projected = $this->projected($granting);
        self::assertInstanceOf(
            DecisionKeyGranting::class,
            $projected,
        );

        $keyholder = $this->keyholderOf($projected);
        self::assertNotNull($keyholder);
        self::assertSame(
            $member->lidnr,
            $keyholder->member->lidnr,
        );

        $this->service->revertRelated($projected);
        $this->projection->flush();

        self::assertNull($this->keyholderOf($projected));

        $this->service->generateRelated($projected);
        $this->projection->flush();

        self::assertNotNull($this->keyholderOf($projected));
    }

    public function testAKeyWithdrawalDatesTheKeyholderAndRevertingUndoesIt(): void
    {
        $granting = $this->build->grantKey(
            $this->build->meeting(),
            $this->build->member(),
        );
        $withdrawal = $this->build->withdrawKey(
            $this->build->meeting(date: '2027-11-01'),
            $granting,
            '2027-10-15',
        );

        $projectedWithdrawal = $this->projected($withdrawal);
        self::assertInstanceOf(
            DecisionKeyWithdrawal::class,
            $projectedWithdrawal,
        );
        $projectedGranting = $this->projected($granting);
        self::assertSame(
            '2027-10-15',
            $this->keyholderOf($projectedGranting)?->withdrawnDate?->format('Y-m-d'),
        );

        $this->service->revertRelated($projectedWithdrawal);
        $this->projection->flush();

        self::assertNull(
            $this->keyholderOf($projectedGranting)->withdrawnDate,
        );

        $this->service->generateRelated($projectedWithdrawal);
        $this->projection->flush();

        self::assertSame(
            '2027-10-15',
            $this->keyholderWithdrawnOn($projectedGranting),
        );
    }

    /**
     * A subdecision that turned out to be wrong is replaced, and its reference to what it pointed at is then empty.
     * Doctrine leaves such a property uninitialised rather than null, which is what this has to look past.
     */
    public function testStillReferencesReportsWhetherThePointedAtEntityExists(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDS',
            'Referentiecommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $discharge = $this->build->discharge(
            $this->build->meeting(date: '2027-02-01'),
            $installation,
        );
        $reappointment = $this->build->reappoint(
            $this->build->meeting(date: '2027-03-01'),
            $installation,
        );
        $granting = $this->build->grantKey(
            $this->build->meeting(date: '2027-04-01'),
            $this->build->member(),
        );
        $withdrawal = $this->build->withdrawKey(
            $this->build->meeting(date: '2027-05-01'),
            $granting,
        );
        $boardInstallation = $this->build->installBoard(
            $this->build->meeting(date: '2027-06-01'),
            $this->build->member(),
        );
        $boardRelease = $this->build->releaseBoard(
            $this->build->meeting(date: '2027-07-01'),
            $boardInstallation,
        );
        $boardDischarge = $this->boardDischarge(
            $this->build->meeting(date: '2027-08-01'),
            $boardInstallation,
        );
        $annulment = $this->build->annul(
            $this->build->meeting(date: '2027-09-01'),
            $foundation->decision,
        );

        $projectedInstallation = $this->projected(
            $installation,
            DecisionInstallation::class,
        );
        $projectedDischarge = $this->projected(
            $discharge,
            DecisionDischarge::class,
        );
        $projectedReappointment = $this->projected(
            $reappointment,
            DecisionReappointment::class,
        );
        $projectedWithdrawal = $this->projected(
            $withdrawal,
            DecisionKeyWithdrawal::class,
        );
        $projectedBoardRelease = $this->projected(
            $boardRelease,
            DecisionBoardRelease::class,
        );
        $projectedBoardDischarge = $this->projected(
            $boardDischarge,
            DecisionBoardDischarge::class,
        );
        $projectedAnnulment = $this->projected(
            $annulment,
            DecisionAnnulment::class,
        );

        self::assertTrue($this->service->stillReferences($projectedInstallation));
        self::assertTrue($this->service->stillReferences($projectedDischarge));
        self::assertTrue($this->service->stillReferences($projectedReappointment));
        self::assertTrue($this->service->stillReferences($projectedWithdrawal));
        self::assertTrue($this->service->stillReferences($projectedBoardRelease));
        self::assertTrue($this->service->stillReferences($projectedBoardDischarge));
        self::assertTrue($this->service->stillReferences($projectedAnnulment));
        // Nothing to point at to begin with, so nothing can be missing.
        self::assertTrue($this->service->stillReferences($this->projected($foundation)));

        // Doctrine leaves a typed reference uninitialised when the column behind it is empty; an instance built
        // without its constructor is that state, without having to write the row empty first.
        self::assertFalse($this->service->stillReferences(self::withoutReference(DecisionInstallation::class)));
        self::assertFalse($this->service->stillReferences(self::withoutReference(DecisionDischarge::class)));
        self::assertFalse($this->service->stillReferences(self::withoutReference(DecisionReappointment::class)));
        self::assertFalse($this->service->stillReferences(self::withoutReference(DecisionKeyWithdrawal::class)));
        self::assertFalse($this->service->stillReferences(self::withoutReference(DecisionBoardRelease::class)));
        self::assertFalse($this->service->stillReferences(self::withoutReference(DecisionBoardDischarge::class)));
        self::assertFalse($this->service->stillReferences(self::withoutReference(DecisionAnnulment::class)));
    }

    /**
     * A body keeps the decisions it was shaped by, and a subdecision that is about to be deleted has to leave them.
     */
    public function testDetachFromOrgansTakesASubdecisionOutOfItsBody(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDX',
            'Detachcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );

        $projectedInstallation = $this->projected($installation);
        $organ = $this->organOf('SDX');

        self::assertNotNull($organ);
        self::assertTrue(
            in_array(
                $projectedInstallation,
                $organ->getOrderedSubdecisions(),
                true,
            ),
        );

        $this->service->detachFromOrgans($projectedInstallation);
        $this->projection->flush();

        self::assertFalse(
            in_array(
                $projectedInstallation,
                $organ->getOrderedSubdecisions(),
                true,
            ),
        );
    }

    /**
     * An installation cannot be derived in a body that is not there; the replay reports it rather than inventing one.
     */
    public function testGenerateRelatedThrowsWhenTheOrganOfAnInstallationIsGone(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDE',
            'Foutcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );

        $organ = $this->organOf('SDE');
        self::assertNotNull($organ);
        $this->projection->remove($organ);
        $this->projection->flush();
        $this->projection->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Installation without Organ');

        $this->service->generateRelated($this->projected($installation));
    }

    /**
     * Reverting is tolerant of what a rebuild has not stepped through yet: nothing to remove is not an error.
     */
    public function testRevertRelatedToleratesAMissingOrgan(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SDZ',
            'Tolerantecommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );

        $organ = $this->organOf('SDZ');
        self::assertNotNull($organ);
        $this->projection->remove($organ);
        $this->projection->flush();
        $this->projection->clear();

        $projectedInstallation = $this->projected($installation);

        $this->service->revertRelated($projectedInstallation);
        $this->projection->flush();

        self::assertNull($this->organMemberOf($projectedInstallation));
    }

    private function boardDischarge(
        Meeting $meeting,
        DatabaseBoardInstallation $installation,
    ): DatabaseBoardDischarge {
        $discharge = new DatabaseBoardDischarge();
        $discharge->installation = $installation;
        $discharge->sequence = 1;
        $discharge->setDecision($this->build->decision($meeting));

        $this->ledger->persist($discharge);
        $this->ledger->flush();

        return $discharge;
    }

    /**
     * @template T of DecisionSubDecision
     *
     * @param class-string<T>|null $type
     *
     * @return ($type is class-string<T> ? T : DecisionSubDecision)
     */
    private function projected(
        DatabaseSubDecision $subDecision,
        ?string $type = null,
    ): DecisionSubDecision {
        $projected = $this->projection->getRepository(DecisionSubDecision::class)->find([
            'meeting_type' => $subDecision->getMeetingType(),
            'meeting_number' => $subDecision->getMeetingNumber(),
            'decision_point' => $subDecision->getDecisionPoint(),
            'decision_number' => $subDecision->getDecisionNumber(),
            'sequence' => $subDecision->sequence,
        ]);

        self::assertNotNull($projected);

        if (null !== $type) {
            self::assertInstanceOf(
                $type,
                $projected,
            );
        }

        return $projected;
    }

    private function organOf(string $abbreviation): ?Organ
    {
        return $this->projection->getRepository(Organ::class)
            ->findOneBy(['abbr' => $abbreviation]);
    }

    private function organMemberOf(DecisionSubDecision $installation): ?OrganMember
    {
        return $this->projection->getRepository(OrganMember::class)
            ->findOneBy(['installation' => $installation]);
    }

    private function boardMemberOf(DecisionSubDecision $installation): ?BoardMember
    {
        return $this->projection->getRepository(BoardMember::class)
            ->findOneBy(['installationDec' => $installation]);
    }

    private function keyholderOf(DecisionSubDecision $granting): ?Keyholder
    {
        return $this->projection->getRepository(Keyholder::class)
            ->findOneBy(['grantingDec' => $granting]);
    }

    private function organMemberDischargedOn(DecisionSubDecision $installation): string
    {
        $organMember = $this->organMemberOf($installation);
        self::assertNotNull($organMember);

        return self::dateLabel($organMember->dischargeDate);
    }

    private function boardMemberReleasedOn(DecisionSubDecision $installation): string
    {
        $boardMember = $this->boardMemberOf($installation);
        self::assertNotNull($boardMember);

        return self::dateLabel($boardMember->releaseDate);
    }

    private function boardMemberDischargedOn(DecisionSubDecision $installation): string
    {
        $boardMember = $this->boardMemberOf($installation);
        self::assertNotNull($boardMember);

        return self::dateLabel($boardMember->dischargeDate);
    }

    private function keyholderWithdrawnOn(DecisionSubDecision $granting): string
    {
        $keyholder = $this->keyholderOf($granting);
        self::assertNotNull($keyholder);

        return self::dateLabel($keyholder->withdrawnDate);
    }

    /**
     * @param class-string<DecisionSubDecision> $class
     */
    private static function withoutReference(string $class): DecisionSubDecision
    {
        $subDecision = new ReflectionClass($class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(
            DecisionSubDecision::class,
            $subDecision,
        );

        return $subDecision;
    }

    private static function dateLabel(?DateTimeImmutable $date): string
    {
        self::assertNotNull($date);

        return $date->format('Y-m-d');
    }
}
