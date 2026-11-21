<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository\Decision\SubDecision;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Database\Meeting;
use App\Entity\Database\SubDecision as Ledger;
use App\Entity\Decision\SubDecision as Projection;
use App\Repository\Database\SubDecision as LedgerRepository;
use App\Repository\Decision\SubDecision as ProjectionRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Override;

use function assert;

final class SubDecisionRepositoriesTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;

    private LedgerBuilder $build;

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
        $this->build = new LedgerBuilder($ledger);
    }

    public function testFoundationRepository(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'SF1',
            'Subdecision foundation',
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\FoundationRepository::class,
            Ledger\Foundation::class,
            $this->address($foundation),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\FoundationRepository::class,
            Projection\Foundation::class,
            $this->address($foundation),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'SF1',
            $ledgerRow->abbr,
        );
        self::assertSame(
            'SF1',
            $projectionRow->abbr,
        );
        self::assertSame(
            'committee',
            $projectionRow->organType->value,
        );
    }

    public function testInstallationRepository(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $organ,
            $member,
            InstallationFunctions::Member,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\InstallationRepository::class,
            Ledger\Installation::class,
            $this->address($installation),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\InstallationRepository::class,
            Projection\Installation::class,
            $this->address($installation),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            InstallationFunctions::Member,
            $ledgerRow->function,
        );
        self::assertSame(
            InstallationFunctions::Member,
            $projectionRow->function,
        );
    }

    public function testDischargeRepository(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $organ,
            $member,
            InstallationFunctions::Member,
        );
        $discharge = $this->build->discharge(
            $meeting,
            $installation,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\DischargeRepository::class,
            Ledger\Discharge::class,
            $this->address($discharge),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\DischargeRepository::class,
            Projection\Discharge::class,
            $this->address($discharge),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            1,
            $ledgerRow->installation->sequence,
        );
        self::assertSame(
            1,
            $projectionRow->installation->sequence,
        );
    }

    public function testReappointmentRepository(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $member = $this->build->member();
        $installation = $this->build->install(
            $meeting,
            $organ,
            $member,
            InstallationFunctions::Member,
        );
        $reappointment = $this->build->reappoint(
            $meeting,
            $installation,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\ReappointmentRepository::class,
            Ledger\Reappointment::class,
            $this->address($reappointment),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\ReappointmentRepository::class,
            Projection\Reappointment::class,
            $this->address($reappointment),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            1,
            $ledgerRow->installation->sequence,
        );
        self::assertSame(
            1,
            $projectionRow->installation->sequence,
        );
    }

    public function testAbrogationRepository(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $abrogation = $this->build->abrogate(
            $meeting,
            $organ,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\AbrogationRepository::class,
            Ledger\Abrogation::class,
            $this->address($abrogation),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\AbrogationRepository::class,
            Projection\Abrogation::class,
            $this->address($abrogation),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'TC',
            $ledgerRow->foundation->abbr,
        );
        self::assertSame(
            'TC',
            $projectionRow->foundation->abbr,
        );
    }

    public function testContinuationRepository(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $continuation = new Ledger\Continuation();
        $continuation->foundation = $organ;
        $this->persistSubDecision(
            $meeting,
            $continuation,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\ContinuationRepository::class,
            Ledger\Continuation::class,
            $this->address($continuation),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\ContinuationRepository::class,
            Projection\Continuation::class,
            $this->address($continuation),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'TC',
            $ledgerRow->foundation->abbr,
        );
        self::assertSame(
            'TC',
            $projectionRow->foundation->abbr,
        );
    }

    public function testOrganRegulationRepository(): void
    {
        $meeting = $this->build->meeting();
        $regulation = $this->build->approveOrganRegulation(
            $meeting,
            '2026-01-01',
            'SFR',
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\OrganRegulationRepository::class,
            Ledger\OrganRegulation::class,
            $this->address($regulation),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\OrganRegulationRepository::class,
            Projection\OrganRegulation::class,
            $this->address($regulation),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'SFR',
            $ledgerRow->abbr,
        );
        self::assertSame(
            'SFR',
            $projectionRow->abbr,
        );
        self::assertSame(
            '1.0',
            $projectionRow->version,
        );
    }

    public function testFoundationReferenceRepositoryReturnsTheReferences(): void
    {
        $meeting = $this->build->meeting();
        $organ = $this->build->foundOrgan($meeting);
        $member = $this->build->member();
        $this->build->install(
            $meeting,
            $organ,
            $member,
            InstallationFunctions::Member,
        );

        $ledgerRows = self::getContainer()
            ->get(LedgerRepository\FoundationReferenceRepository::class)
            ->findAll();
        $projectionRows = self::getContainer()
            ->get(ProjectionRepository\FoundationReferenceRepository::class)
            ->findAll();

        self::assertNotEmpty($ledgerRows);
        self::assertNotEmpty($projectionRows);
        foreach ($ledgerRows as $row) {
            self::assertInstanceOf(
                Ledger\FoundationReference::class,
                $row,
            );
        }

        foreach ($projectionRows as $row) {
            self::assertInstanceOf(
                Projection\FoundationReference::class,
                $row,
            );
        }
    }

    public function testBudgetRepository(): void
    {
        $meeting = $this->build->meeting();
        $budget = $this->build->approveBudget(
            $meeting,
            '2026-01-01',
            'Subdecisionbegroting',
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Financial\BudgetRepository::class,
            Ledger\Financial\Budget::class,
            $this->address($budget),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Financial\BudgetRepository::class,
            Projection\Financial\Budget::class,
            $this->address($budget),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'Subdecisionbegroting',
            $ledgerRow->name,
        );
        self::assertSame(
            'Subdecisionbegroting',
            $projectionRow->name,
        );
        self::assertTrue($projectionRow->approval);
    }

    public function testStatementRepository(): void
    {
        $meeting = $this->build->meeting();
        $statement = new Ledger\Financial\Statement();
        $statement->name = 'Subdecisionafrekening';
        $statement->version = '2.0';
        $statement->date = new DateTimeImmutable('2026-01-01');
        $statement->approval = true;
        $statement->changes = false;
        $statement->setMember($this->build->member());
        $this->persistSubDecision(
            $meeting,
            $statement,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Financial\StatementRepository::class,
            Ledger\Financial\Statement::class,
            $this->address($statement),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Financial\StatementRepository::class,
            Projection\Financial\Statement::class,
            $this->address($statement),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'Subdecisionafrekening',
            $ledgerRow->name,
        );
        self::assertSame(
            'Subdecisionafrekening',
            $projectionRow->name,
        );
        self::assertFalse($projectionRow->changes);
    }

    public function testMinutesRepository(): void
    {
        $meeting = $this->build->meeting();
        $target = $this->build->meeting();
        $minutes = new Ledger\Minutes();
        $minutes->setMember($this->build->member());
        $minutes->setTarget($target);
        $minutes->approval = true;
        $minutes->changes = false;
        $this->persistSubDecision(
            $meeting,
            $minutes,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\MinutesRepository::class,
            Ledger\Minutes::class,
            $this->address($minutes),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\MinutesRepository::class,
            Projection\Minutes::class,
            $this->address($minutes),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            $target->getNumber(),
            $ledgerRow->getTarget()->getNumber(),
        );
        self::assertSame(
            $target->getNumber(),
            $projectionRow->meeting->number,
        );
        self::assertTrue($projectionRow->approval);
    }

    public function testOtherRepository(): void
    {
        $meeting = $this->build->meeting();
        $other = $this->build->decideFreely(
            $meeting,
            'Een vrije beslissing',
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\OtherRepository::class,
            Ledger\Other::class,
            $this->address($other),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\OtherRepository::class,
            Projection\Other::class,
            $this->address($other),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'Een vrije beslissing',
            $ledgerRow->contentNL,
        );
        self::assertSame(
            'Een vrije beslissing',
            $projectionRow->contentNL,
        );
    }

    public function testBoardInstallationRepository(): void
    {
        $meeting = $this->build->meeting();
        $installation = $this->build->installBoard(
            $meeting,
            $this->build->member(),
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Board\InstallationRepository::class,
            Ledger\Board\Installation::class,
            $this->address($installation),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Board\InstallationRepository::class,
            Projection\Board\Installation::class,
            $this->address($installation),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'Voorzitter',
            $ledgerRow->function->value,
        );
        self::assertSame(
            'Voorzitter',
            $projectionRow->function->value,
        );
    }

    public function testBoardReleaseRepository(): void
    {
        $meeting = $this->build->meeting();
        $installation = $this->build->installBoard(
            $meeting,
            $this->build->member(),
        );
        $release = $this->build->releaseBoard(
            $meeting,
            $installation,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Board\ReleaseRepository::class,
            Ledger\Board\Release::class,
            $this->address($release),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Board\ReleaseRepository::class,
            Projection\Board\Release::class,
            $this->address($release),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'Voorzitter',
            $ledgerRow->installation->function->value,
        );
        self::assertSame(
            'Voorzitter',
            $projectionRow->installation->function->value,
        );
    }

    public function testBoardDischargeRepository(): void
    {
        $meeting = $this->build->meeting();
        $installation = $this->build->installBoard(
            $meeting,
            $this->build->member(),
        );
        $discharge = new Ledger\Board\Discharge();
        $discharge->installation = $installation;
        $this->persistSubDecision(
            $meeting,
            $discharge,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Board\DischargeRepository::class,
            Ledger\Board\Discharge::class,
            $this->address($discharge),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Board\DischargeRepository::class,
            Projection\Board\Discharge::class,
            $this->address($discharge),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            'Voorzitter',
            $ledgerRow->installation->function->value,
        );
        self::assertSame(
            'Voorzitter',
            $projectionRow->installation->function->value,
        );
    }

    public function testBoardCandidacyRepository(): void
    {
        $meeting = $this->build->meeting();
        $candidacy = new Ledger\Board\Candidacy();
        $candidacy->boardYear = 2027;
        $this->persistSubDecision(
            $meeting,
            $candidacy,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Board\CandidacyRepository::class,
            Ledger\Board\Candidacy::class,
            $this->address($candidacy),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Board\CandidacyRepository::class,
            Projection\Board\Candidacy::class,
            $this->address($candidacy),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            2027,
            $ledgerRow->boardYear,
        );
        self::assertSame(
            2027,
            $projectionRow->boardYear,
        );
    }

    public function testBoardCandidateRepository(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $candidate = new Ledger\Board\Candidate();
        $candidate->setMember($member);
        $this->persistSubDecision(
            $meeting,
            $candidate,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Board\CandidateRepository::class,
            Ledger\Board\Candidate::class,
            $this->address($candidate),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Board\CandidateRepository::class,
            Projection\Board\Candidate::class,
            $this->address($candidate),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            $member->lidnr,
            $ledgerRow->getMember()->lidnr,
        );
        self::assertSame(
            $member->lidnr,
            $projectionRow->getMember()->lidnr,
        );
    }

    public function testKeyGrantingRepository(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $granting = $this->build->grantKey(
            $meeting,
            $member,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Key\GrantingRepository::class,
            Ledger\Key\Granting::class,
            $this->address($granting),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Key\GrantingRepository::class,
            Projection\Key\Granting::class,
            $this->address($granting),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            $member->lidnr,
            $ledgerRow->getMember()->lidnr,
        );
        self::assertSame(
            $member->lidnr,
            $projectionRow->getMember()->lidnr,
        );
    }

    public function testKeyWithdrawalRepository(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $granting = $this->build->grantKey(
            $meeting,
            $member,
        );
        $withdrawal = $this->build->withdrawKey(
            $meeting,
            $granting,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Key\WithdrawalRepository::class,
            Ledger\Key\Withdrawal::class,
            $this->address($withdrawal),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Key\WithdrawalRepository::class,
            Projection\Key\Withdrawal::class,
            $this->address($withdrawal),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            $member->lidnr,
            $ledgerRow->granting->getMember()->lidnr,
        );
        self::assertSame(
            $member->lidnr,
            $projectionRow->granting->getMember()->lidnr,
        );
    }

    public function testMemberWarningRepository(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $warning = new Ledger\Member\Warning();
        $warning->setMember($member);
        $this->persistSubDecision(
            $meeting,
            $warning,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Member\WarningRepository::class,
            Ledger\Member\Warning::class,
            $this->address($warning),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Member\WarningRepository::class,
            Projection\Member\Warning::class,
            $this->address($warning),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            $member->lidnr,
            $ledgerRow->getMember()->lidnr,
        );
        self::assertSame(
            $member->lidnr,
            $projectionRow->getMember()->lidnr,
        );
    }

    public function testMemberSuspensionRepository(): void
    {
        $meeting = $this->build->meeting();
        $member = $this->build->member();
        $suspension = new Ledger\Member\Suspension();
        $suspension->setMember($member);
        $suspension->since = new DateTimeImmutable('2026-01-01');
        $suspension->until = new DateTimeImmutable('2026-02-01');
        $this->persistSubDecision(
            $meeting,
            $suspension,
        );

        $ledgerRow = $this->findOne(
            LedgerRepository\Member\SuspensionRepository::class,
            Ledger\Member\Suspension::class,
            $this->address($suspension),
        );
        $projectionRow = $this->findOne(
            ProjectionRepository\Member\SuspensionRepository::class,
            Projection\Member\Suspension::class,
            $this->address($suspension),
        );

        self::assertNotNull($ledgerRow);
        self::assertNotNull($projectionRow);
        self::assertSame(
            '2026-02-01',
            $ledgerRow->until->format('Y-m-d'),
        );
        self::assertSame(
            '2026-02-01',
            $projectionRow->until->format('Y-m-d'),
        );
        self::assertSame(
            $member->lidnr,
            $projectionRow->getMember()->lidnr,
        );
    }

    private function persistSubDecision(
        Meeting $meeting,
        Ledger $subDecision,
    ): void {
        $decision = $this->build->decision($meeting);
        $subDecision->sequence = 1;
        $subDecision->setDecision($decision);

        $this->ledger->persist($decision);
        $this->ledger->persist($subDecision);
        $this->ledger->flush();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $address
     *
     * @return T|null
     */
    private function findOne(
        string $repository,
        string $class,
        array $address,
    ): ?object {
        $repositoryObject = self::getContainer()->get($repository);
        assert($repositoryObject instanceof ObjectRepository);
        $row = $repositoryObject->findOneBy($address);

        return $row instanceof $class
            ? $row
            : null;
    }

    /**
     * @return array{
     *     meeting_type: MeetingTypes,
     *     meeting_number: int,
     *     decision_point: int,
     *     decision_number: int,
     *     sequence: int,
     * }
     */
    private function address(Ledger $subDecision): array
    {
        return [
            'meeting_type' => $subDecision->getMeetingType(),
            'meeting_number' => $subDecision->getMeetingNumber(),
            'decision_point' => $subDecision->getDecisionPoint(),
            'decision_number' => $subDecision->getDecisionNumber(),
            'sequence' => $subDecision->sequence,
        ];
    }
}
