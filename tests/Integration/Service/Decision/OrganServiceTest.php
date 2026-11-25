<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Database\SubDecision as DatabaseSubDecision;
use App\Entity\Decision\Member as DecisionMember;
use App\Entity\Decision\Organ as DecisionOrgan;
use App\Entity\Decision\OrganMember as DecisionOrganMember;
use App\Entity\Decision\SubDecision as DecisionSubDecision;
use App\Entity\Decision\SubDecision\Abrogation as DecisionAbrogation;
use App\Entity\Decision\SubDecision\Discharge as DecisionDischarge;
use App\Entity\Decision\SubDecision\Foundation as DecisionFoundation;
use App\Entity\Decision\SubDecision\Installation as DecisionInstallation;
use App\Service\Decision\OrganService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

use function in_array;

/**
 * The organ side of the projection: the bodies themselves, when they were founded and abolished, and who was in them.
 * A body is never written directly; it is derived from the decisions about it, which is what this service does.
 */
#[CoversClass(OrganService::class)]
final class OrganServiceTest extends DatabaseTestCase
{
    private EntityManagerInterface $projection;
    private LedgerBuilder $build;
    private OrganService $service;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $ledger,
        );

        $this->projection = $this->entityManager;
        $this->build = new LedgerBuilder($ledger);
        $this->service = self::getContainer()->get(OrganService::class);
    }

    public function testGenerateFoundationCreatesTheOrgan(): void
    {
        $meeting = $this->build->meeting(date: '2026-05-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ORG',
            'Organisatiecommissie',
            OrganTypes::Committee,
        );

        $projectedFoundation = $this->projected(
            $foundation,
            DecisionFoundation::class,
        );
        self::assertInstanceOf(
            DecisionFoundation::class,
            $projectedFoundation,
        );

        $organ = $this->service->generateFoundation($projectedFoundation);
        $this->projection->flush();

        self::assertSame(
            'ORG',
            $organ->abbr,
        );
        self::assertSame(
            'Organisatiecommissie',
            $organ->name,
        );
        self::assertSame(
            OrganTypes::Committee,
            $organ->type,
        );
        self::assertSame(
            '2026-05-01',
            $organ->foundationDate->format('Y-m-d'),
        );
        self::assertSame(
            $projectedFoundation,
            $organ->foundation,
        );
        self::assertTrue(
            in_array(
                $projectedFoundation,
                $organ->getOrderedSubdecisions(),
                true,
            ),
        );
    }

    public function testGenerateFoundationUpdatesTheOrganRatherThanFoundingAnother(): void
    {
        $foundation = $this->build->foundOrgan(
            $this->build->meeting(),
            'ORT',
            'Oude naam',
        );
        $this->projection->clear();

        $projectedFoundation = $this->projected(
            $foundation,
            DecisionFoundation::class,
        );
        $projectedFoundation->abbr = 'ORU';
        $projectedFoundation->name = 'Nieuwe naam';

        $this->service->generateFoundation($projectedFoundation);
        $this->projection->flush();
        $this->projection->clear();

        $organ = $this->organOf('ORU');

        self::assertNotNull($organ);
        self::assertSame(
            'Nieuwe naam',
            $organ->name,
        );
        self::assertSame(
            1,
            $this->projection->getRepository(DecisionOrgan::class)->count([
                'foundation' => $this->projected(
                    $foundation,
                    DecisionFoundation::class,
                ),
            ]),
        );
    }

    /**
     * A body that is founded, abolished and founded again under the same abbreviation is two records, each with its
     * own dates, not one record that moves.
     */
    public function testAFoundationAfterAnAbrogationIsANewVersionOfTheBody(): void
    {
        $first = $this->build->foundOrgan(
            $this->build->meeting(date: '2026-01-01'),
            'VER',
            'Eerste versie',
        );
        $this->build->abrogate(
            $this->build->meeting(date: '2026-06-01'),
            $first,
        );
        $this->build->foundOrgan(
            $this->build->meeting(date: '2026-07-01'),
            'VER',
            'Tweede versie',
        );

        $organs = $this->projection->getRepository(DecisionOrgan::class)
            ->findBy(['abbr' => 'VER']);

        self::assertCount(
            2,
            $organs,
        );

        $byName = [];
        foreach ($organs as $organ) {
            $byName[$organ->name] = $organ;
        }

        self::assertSame(
            '2026-06-01',
            $byName['Eerste versie']->abrogationDate?->format('Y-m-d'),
        );
        self::assertNull($byName['Tweede versie']->abrogationDate);
        self::assertSame(
            '2026-07-01',
            $byName['Tweede versie']->foundationDate->format('Y-m-d'),
        );
    }

    public function testGenerateAbrogationDatesTheOrganAndDischargesItsMembers(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'OAB',
            'Abrogatiecommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Chair,
        );
        $abrogation = $this->build->abrogate(
            $this->build->meeting(date: '2026-06-15'),
            $foundation,
        );

        $projectedAbrogation = $this->projected(
            $abrogation,
            DecisionAbrogation::class,
        );
        self::assertInstanceOf(
            DecisionAbrogation::class,
            $projectedAbrogation,
        );

        $this->service->generateAbrogation($projectedAbrogation);
        $this->projection->flush();

        $organ = $this->organOf('OAB');
        self::assertNotNull($organ);
        self::assertSame(
            '2026-06-15',
            $organ->abrogationDate?->format('Y-m-d'),
        );

        $organMember = $this->organMemberOf(
            $this->projected(
                $installation,
                DecisionInstallation::class,
            ),
        );
        self::assertNotNull($organMember);
        self::assertSame(
            '2026-06-15',
            $organMember->dischargeDate?->format('Y-m-d'),
        );
    }

    /**
     * Someone who was already discharged when their body was abolished stays discharged on the day they left.
     */
    public function testGenerateAbrogationLeavesAnAlreadyDischargedMemberAlone(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'OAD',
            'Ontslagcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $this->build->discharge(
            $this->build->meeting(date: '2026-03-01'),
            $installation,
        );
        $abrogation = $this->build->abrogate(
            $this->build->meeting(date: '2026-06-15'),
            $foundation,
        );

        $this->service->generateAbrogation($this->projected($abrogation, DecisionAbrogation::class));
        $this->projection->flush();

        $organMember = $this->organMemberOf(
            $this->projected(
                $installation,
                DecisionInstallation::class,
            ),
        );
        self::assertNotNull($organMember);
        self::assertSame(
            '2026-03-01',
            $organMember->dischargeDate?->format('Y-m-d'),
        );

        // Abolishing it again cannot move a discharge that was already there.
        $this->service->generateAbrogation($this->projected($abrogation, DecisionAbrogation::class));
        $this->projection->flush();

        self::assertSame(
            '2026-03-01',
            $organMember->dischargeDate->format('Y-m-d'),
        );
    }

    public function testGenerateAbrogationThrowsWhenTheOrganIsGone(): void
    {
        $foundation = $this->build->foundOrgan(
            $this->build->meeting(),
            'OAM',
            'Verdwenencommissie',
        );
        $abrogation = $this->build->abrogate(
            $this->build->meeting(date: '2026-06-15'),
            $foundation,
        );

        $organ = $this->organOf('OAM');
        self::assertNotNull($organ);
        $this->projection->remove($organ);
        $this->projection->flush();
        $this->projection->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Abrogation without Organ');

        $this->service->generateAbrogation($this->projected($abrogation, DecisionAbrogation::class));
    }

    public function testGenerateInstallationAddsEveryFunctionOfTheDecision(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'OIN',
            'Installatiecommissie',
        );
        $member = $this->build->member();
        $chair = $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Chair,
            InstallationFunctions::Secretary,
        );

        $organMembers = $this->projection->getRepository(DecisionOrganMember::class)
            ->findBy(['organ' => $this->organOf('OIN')]);

        self::assertCount(
            2,
            $organMembers,
        );

        $functions = [];
        foreach ($organMembers as $organMember) {
            $functions[] = $organMember->function;
            self::assertSame(
                $member->lidnr,
                $organMember->member->lidnr,
            );
            self::assertSame(
                '2026-02-01',
                $organMember->installDate->format('Y-m-d'),
            );
            self::assertNull($organMember->dischargeDate);
        }

        self::assertEqualsCanonicalizing(
            [
                InstallationFunctions::Chair,
                InstallationFunctions::Secretary,
            ],
            $functions,
        );

        // Calling it again finds the memberships instead of adding a second pair.
        foreach ($chair->decision->getSubdecisions() as $subDecision) {
            $this->service->generateInstallation($this->projected($subDecision, DecisionInstallation::class));
        }

        $this->projection->flush();

        self::assertCount(
            2,
            $this->projection->getRepository(DecisionOrganMember::class)
                ->findBy(['organ' => $this->organOf('OIN')]),
        );
    }

    public function testGenerateInstallationThrowsWhenTheOrganIsGone(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'OIE',
            'Foutcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );

        $organ = $this->organOf('OIE');
        self::assertNotNull($organ);
        $this->projection->remove($organ);
        $this->projection->flush();
        $this->projection->clear();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Installation without Organ');

        $projected = $this->projected(
            $installation,
            DecisionInstallation::class,
        );
        self::assertInstanceOf(
            DecisionInstallation::class,
            $projected,
        );

        $this->service->generateInstallation($projected);
    }

    public function testGenerateDischargeOnlyEndsTheInstallationItNames(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ODIS',
            'Ontslagcommissie',
        );
        $first = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Chair,
        );
        $second = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Secretary,
        );

        $discharge = $this->build->discharge(
            $this->build->meeting(date: '2026-05-01'),
            $first,
        );

        $this->service->generateDischarge($this->projected($discharge, DecisionDischarge::class));
        $this->projection->flush();

        $firstMember = $this->organMemberOf(
            $this->projected($first),
        );
        $secondMember = $this->organMemberOf(
            $this->projected($second),
        );

        self::assertNotNull($firstMember);
        self::assertNotNull($secondMember);
        self::assertSame(
            '2026-05-01',
            $firstMember->dischargeDate?->format('Y-m-d'),
        );
        self::assertNull($secondMember->dischargeDate);
        self::assertCount(
            2,
            $this->organOf('ODIS')?->getMembers() ?? [],
        );
    }

    /**
     * An installation that was annulled before the discharge was decided never put anyone in the body, so there is
     * nobody to discharge.
     */
    public function testGenerateDischargeWithoutAnOrganMemberDoesNothing(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ODN',
            'Nooitgeweestcommissie',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $this->build->annul(
            $this->build->meeting(date: '2026-03-01'),
            $installation->decision,
        );
        $discharge = $this->build->discharge(
            $this->build->meeting(date: '2026-04-01'),
            $installation,
        );

        self::assertNull(
            $this->organMemberOf(
                $this->projected(
                    $installation,
                    DecisionInstallation::class,
                ),
            ),
        );

        $this->projection->clear();

        // A second pass over the discharge finds the same nothing, and says nothing about it.
        $this->service->generateDischarge($this->projected($discharge, DecisionDischarge::class));
        $this->projection->flush();

        self::assertNull(
            $this->organMemberOf(
                $this->projected(
                    $installation,
                    DecisionInstallation::class,
                ),
            ),
        );
    }

    /**
     * The ledger refuses to remove a member a decision names; the projection refuses it through the same constraint.
     */
    public function testAnInstalledMemberCannotBeRemovedFromTheProjection(): void
    {
        $meeting = $this->build->meeting(date: '2026-02-01');
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ODR',
            'Onverwijderbarecommissie',
        );
        $member = $this->build->member();
        $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );

        $projectedMember = $this->projection->getRepository(DecisionMember::class)
            ->find($member->lidnr);
        self::assertNotNull($projectedMember);

        $this->projection->remove($projectedMember);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->projection->flush();
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

    private function organOf(string $abbreviation): ?DecisionOrgan
    {
        return $this->projection->getRepository(DecisionOrgan::class)
            ->findOneBy(['abbr' => $abbreviation]);
    }

    private function organMemberOf(DecisionSubDecision $installation): ?DecisionOrganMember
    {
        return $this->projection->getRepository(DecisionOrganMember::class)
            ->findOneBy(['installation' => $installation]);
    }
}
