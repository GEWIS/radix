<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener\Report;

use App\Entity\Database\Enums\InstallationFunctions;
use App\Entity\Database\Enums\Studies;
use App\Entity\Database\SubDecision\Other;
use App\Entity\Decision\Decision as ReportDecision;
use App\Entity\Decision\Meeting as ReportMeeting;
use App\Entity\Decision\Member as ReportMember;
use App\Entity\Decision\Organ as ReportOrgan;
use App\Entity\Decision\OrganMember as ReportOrganMember;
use App\EventListener\Report\DatabaseDeletionListener;
use App\EventListener\Report\DatabaseUpdateListener;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The projection is a copy of the ledger kept on the web connection, and it is what every page and every API
 * response about a member or a body is built from — the ledger itself is read by almost nothing. What the listeners
 * write as the ledger is written is therefore not an internal detail: it is what the rest of the application sees.
 */
#[CoversClass(DatabaseUpdateListener::class)]
#[CoversClass(DatabaseDeletionListener::class)]
class ProjectionTest extends KernelTestCase
{
    private EntityManagerInterface $ledger;
    private EntityManagerInterface $report;
    private LedgerBuilder $build;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $ledger = self::getContainer()->get(EntityManagerInterface::class);
        $report = self::getContainer()->get('doctrine')->getManager('web');
        self::assertInstanceOf(
            EntityManagerInterface::class,
            $report,
        );

        $this->ledger = $ledger;
        $this->report = $report;
        $this->build = new LedgerBuilder($ledger);
    }

    public function testWritingAMemberWritesTheProjectedMember(): void
    {
        $member = $this->build->member();

        $projected = $this->report->getRepository(ReportMember::class)->find($member->getLidnr());

        self::assertNotNull($projected);
        self::assertSame(
            $member->getLastName(),
            $projected->getLastName(),
        );
        self::assertSame(
            $member->getEmail(),
            $projected->getEmail(),
        );
    }

    /**
     * The study is only recorded in the ledger, so the website can only order sign-ups by program if the projection
     * carries it along.
     */
    public function testWritingAMemberWritesTheirStudy(): void
    {
        $member = $this->build->member();
        $member->setStudy(Studies::MCSE);
        $this->ledger->flush();

        $projected = $this->report->getRepository(ReportMember::class)->find($member->getLidnr());

        self::assertNotNull($projected);
        self::assertSame(
            Studies::MCSE,
            $projected->getStudy(),
        );
    }

    public function testWritingAMeetingWritesTheProjectedMeeting(): void
    {
        $meeting = $this->build->meeting();

        $projected = $this->report->getRepository(ReportMeeting::class)->find([
            'type' => $meeting->getType(),
            'number' => $meeting->getNumber(),
        ]);

        self::assertNotNull($projected);
        self::assertEquals(
            $meeting->getDate(),
            $projected->getDate(),
        );
    }

    /**
     * A foundation is not projected as a subdecision alone: the organ it founds is derived from it, and that is the
     * table the site reads to know which bodies exist.
     */
    public function testFoundingAnOrganDerivesTheOrgan(): void
    {
        $foundation = $this->build->foundOrgan(
            $this->build->meeting(),
            'TTC',
            'Testtaartcommissie',
        );

        $organ = $this->organOf($foundation->getAbbr());

        self::assertNotNull($organ);
        self::assertSame(
            'Testtaartcommissie',
            $organ->getName(),
        );
        self::assertNull($organ->getAbrogationDate());
    }

    public function testAbrogatingAnOrganDatesItRatherThanRemovingIt(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ATC',
        );
        $this->build->abrogate(
            $this->build->meeting(date: '2027-08-20'),
            $foundation,
        );

        $organ = $this->organOf('ATC');

        self::assertNotNull($organ);
        self::assertSame(
            '2027-08-20',
            $organ->getAbrogationDate()?->format('Y-m-d'),
        );
    }

    /**
     * The membership of a body, which is what the API hands out and what a member's page shows.
     */
    public function testInstallingSomeoneDerivesTheirOrganMembership(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'ITC',
        );
        $member = $this->build->member();
        $this->build->install(
            $meeting,
            $foundation,
            $member,
            InstallationFunctions::Member,
        );

        $organMember = $this->organMemberOf('ITC');

        self::assertNotNull($organMember);
        self::assertSame(
            $member->getLidnr(),
            $organMember->getMember()->getLidnr(),
        );
        self::assertSame(
            InstallationFunctions::Member,
            $organMember->getFunction(),
        );
        self::assertNull($organMember->getDischargeDate());
    }

    public function testDischargingSomeoneEndsTheOrganMembershipInPlace(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'DTC',
        );
        $installation = $this->build->install(
            $meeting,
            $foundation,
            $this->build->member(),
            InstallationFunctions::Member,
        );
        $this->build->discharge(
            $this->build->meeting(date: '2027-02-01'),
            $installation,
        );

        $organMember = $this->organMemberOf('DTC');

        self::assertNotNull($organMember);
        self::assertSame(
            '2027-02-01',
            $organMember->getDischargeDate()?->format('Y-m-d'),
        );
    }

    /**
     * The other direction: what leaves the ledger has to leave the projection, or the site goes on showing it.
     */
    public function testRemovingADecisionRemovesWhatWasDerivedFromIt(): void
    {
        $meeting = $this->build->meeting();
        $foundation = $this->build->foundOrgan(
            $meeting,
            'RTC',
        );

        self::assertNotNull($this->organOf('RTC'));

        $this->ledger->remove($foundation->getDecision());
        $this->ledger->flush();

        self::assertNull($this->organOf('RTC'));
    }

    public function testTranslatingAFreeTextDecisionRewritesWhatTheDecisionReadsAs(): void
    {
        $other = $this->build->decideFreely(
            $this->build->meeting(),
            'Er wordt een taart gekocht.',
        );
        $projected = $this->projectedDecisionOf($other);

        self::assertNotNull($projected);
        self::assertSame(
            'Er wordt een taart gekocht.',
            $projected->getContentNL(),
        );
        self::assertSame(
            'If you are reading this, the secretary has not done their job.',
            $projected->getContentEN(),
        );

        $other->setContentEN('A cake is bought.');
        $this->ledger->flush();

        self::assertSame(
            'A cake is bought.',
            $projected->getContentEN(),
        );
        self::assertSame(
            'Er wordt een taart gekocht.',
            $projected->getContentNL(),
        );
    }

    private function projectedDecisionOf(Other $other): ?ReportDecision
    {
        return $this->report->getRepository(ReportDecision::class)->find([
            'meeting_type' => $other->getMeetingType(),
            'meeting_number' => $other->getMeetingNumber(),
            'point' => $other->getDecisionPoint(),
            'number' => $other->getDecisionNumber(),
        ]);
    }

    private function organOf(string $abbreviation): ?ReportOrgan
    {
        return $this->report->getRepository(ReportOrgan::class)->findOneBy(['abbr' => $abbreviation]);
    }

    private function organMemberOf(string $abbreviation): ?ReportOrganMember
    {
        $organ = $this->organOf($abbreviation);

        self::assertNotNull($organ);

        return $this->report->getRepository(ReportOrganMember::class)->findOneBy(['organ' => $organ]);
    }
}
