<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Storage;

use App\Entity\Database\Enums\MeetingTypes;
use App\Entity\Decision\LegacyMeetingDocument;
use App\Entity\Decision\LegacyMeetingMinutes;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingMinutes;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\Decision\MeetingPoint;
use App\Entity\Decision\MeetingReferenceSelection;
use App\Entity\Decision\ReferenceDocument;
use App\Tests\Integration\DatabaseTestCase;
use Override;
use Symfony\Component\Console\Tester\ExecutionResult;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function dirname;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

/**
 * End-to-end run of the legacy document migrator against seeded meetings: version suffixes collapse into one document
 * under a freshly created agenda point, a recurring document becomes a library document with per-meeting pinned
 * selections, minutes become a versioned master, and rows whose file is gone are skipped without creating anything.
 *
 * The migration is idempotent per row, which is what lets it be run long after the launch it was written for: a
 * second run adds nothing, and whatever the board has made in the new model in the meantime is left alone.
 */
final class MigrateLegacyMeetingDocumentsTest extends DatabaseTestCase
{
    private string $sourceDir;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir() . '/legacy-migrator-' . bin2hex(random_bytes(8));
    }

    #[Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->sourceDir);

        parent::tearDown();
    }

    /**
     * Without the pool there is nothing to read, and every row would otherwise be reported as a missing file, which
     * reads like a phase that had nothing left to do.
     */
    public function testRefusesToRunWithoutALegacyPool(): void
    {
        $result = $this->migrateMeetings();

        $this->assertCommandFailed($result);
        self::assertStringContainsString(
            '--source-dir',
            $result->getDisplay(),
        );
    }

    public function testMigratesDocumentsReferencesAndMinutes(): void
    {
        [
            $firstMeeting, $secondMeeting
        ] = $this->oldestAlvMeetings();

        $this->legacyFile('aa/begroting-v1.pdf');
        $this->legacyFile('aa/begroting-v2.pdf');
        $this->legacyFile('aa/edl-old.pdf');
        $this->legacyFile('aa/edl-new.pdf');
        $this->legacyFile('aa/jaarplanning.pdf');
        $this->legacyFile('aa/notulen.pdf');

        $this->legacyDocument(
            $firstMeeting,
            '5.1 Begrotingswijziging (v1.0)',
            'aa/begroting-v1.pdf',
        );
        $this->legacyDocument(
            $firstMeeting,
            '5.1 Begrotingswijziging (v2.0) (03-06-2020)',
            'aa/begroting-v2.pdf',
        );
        $this->legacyDocument(
            $firstMeeting,
            'Jaarplanning commissies',
            'aa/jaarplanning.pdf',
        );
        $this->legacyDocument(
            $firstMeeting,
            'Eternal Decisionlist',
            'aa/edl-old.pdf',
        );
        $this->legacyDocument(
            $secondMeeting,
            'AV stuk 2.3 - Eternal Decisionlist',
            'aa/edl-new.pdf',
        );
        $this->legacyDocument(
            $firstMeeting,
            '9.1 Verdwenen stuk',
            'aa/missing.pdf',
        );

        $this->legacyMinutes(
            $firstMeeting,
            'aa/notulen.pdf',
        );
        $this->entityManager->flush();

        $this->assertCommandIsSuccessful($this->migrateMeetings());

        // The two version-suffixed rows collapsed into one document under a new point 5.
        $document = $this->entityManager->getRepository(MeetingDocument::class)->findOneBy([
            'meeting' => $firstMeeting,
            'name' => 'Begrotingswijziging',
        ]);
        self::assertNotNull($document);
        self::assertSame(
            '5',
            $document->getPoint()?->getNumber(),
        );
        $versions = $document->getVersions()->getValues();
        self::assertCount(
            2,
            $versions,
        );
        self::assertSame(
            'v1.0',
            $versions[0]->getVersionLabel(),
        );
        self::assertSame(
            'v2.0',
            $versions[1]->getVersionLabel(),
        );
        self::assertSame(
            '2020-06-03',
            $versions[1]->getUploadedAt()?->format('Y-m-d'),
        );
        self::assertNull($versions[1]->getUploadedBy());

        // The unparseable name stayed a meeting-level document under its full original name.
        $flat = $this->entityManager->getRepository(MeetingDocument::class)->findOneBy([
            'meeting' => $firstMeeting,
            'name' => 'Jaarplanning commissies',
        ]);
        self::assertNotNull($flat);
        self::assertNull($flat->getPoint());

        // The recurring document became one library document; each meeting is pinned to the version it shipped.
        $reference = $this->entityManager->getRepository(ReferenceDocument::class)->findOneBy([
            'name' => 'Eternal Decision List',
        ]);
        self::assertNotNull($reference);
        $referenceVersions = $reference->getVersions()->getValues();
        self::assertCount(
            2,
            $referenceVersions,
        );

        $selections = $this->entityManager->getRepository(MeetingReferenceSelection::class);
        self::assertSame(
            $referenceVersions[0],
            $selections->findOneBy([
                'meeting' => $firstMeeting,
                'referenceDocument' => $reference,
            ])?->getPinnedVersion(),
        );
        self::assertSame(
            $referenceVersions[1],
            $selections->findOneBy([
                'meeting' => $secondMeeting,
                'referenceDocument' => $reference,
            ])?->getPinnedVersion(),
        );

        // The legacy minutes became a versioned master on the meeting.
        $migratedMinutes = $firstMeeting->getMinutes();
        self::assertNotNull($migratedMinutes);
        $minutesVersions = $migratedMinutes->getVersions()->getValues();
        self::assertCount(
            1,
            $minutesVersions,
        );
        self::assertSame(
            'v1.0',
            $minutesVersions[0]->getVersionLabel(),
        );

        // The row whose file is gone produced nothing, not even its agenda point.
        self::assertNull($this->entityManager->getRepository(MeetingDocument::class)->findOneBy([
            'meeting' => $firstMeeting,
            'name' => 'Verdwenen stuk',
        ]));
        self::assertNull($this->entityManager->getRepository(MeetingPoint::class)->findOneBy([
            'meeting' => $firstMeeting,
            'number' => '9',
        ]));
    }

    public function testDryRunLeavesTheDatabaseUntouched(): void
    {
        $this->legacyFile('aa/begroting-v1.pdf');
        $this->legacyDocument(
            $this->oldestAlvMeetings()[0],
            '5.1 Begrotingswijziging (v1.0)',
            'aa/begroting-v1.pdf',
        );
        $this->entityManager->flush();

        $result = $this->migrateMeetings(dryRun: true);

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString(
            'Dry run: nothing was written.',
            $result->getDisplay(),
        );
        self::assertNull($this->entityManager->getRepository(MeetingDocument::class)->findOneBy([
            'name' => 'Begrotingswijziging',
        ]));
    }

    /**
     * The phase has to survive being run twice: it is the only thing that carries the meeting files out of the pool,
     * and whoever runs it has no way of knowing what an earlier run settled.
     */
    public function testASecondRunAddsNothing(): void
    {
        $meeting = $this->oldestAlvMeetings()[0];

        $this->legacyFile('aa/begroting-v1.pdf');
        $this->legacyFile('aa/notulen.pdf');
        $this->legacyFile('aa/scenarios.pdf');
        $this->legacyDocument(
            $meeting,
            '5.1 Begrotingswijziging (v1.0)',
            'aa/begroting-v1.pdf',
        );
        $this->legacyDocument(
            $meeting,
            'Scenarios and procedures',
            'aa/scenarios.pdf',
        );
        $this->legacyMinutes(
            $meeting,
            'aa/notulen.pdf',
        );
        $this->entityManager->flush();

        $this->assertCommandIsSuccessful($this->migrateMeetings());

        $documents = $this->entityManager->getRepository(MeetingDocument::class)->count(['meeting' => $meeting]);
        $points = $this->entityManager->getRepository(MeetingPoint::class)->count(['meeting' => $meeting]);
        $selections = $this->entityManager->getRepository(MeetingReferenceSelection::class)
            ->count(['meeting' => $meeting]);

        $result = $this->migrateMeetings();

        $this->assertCommandIsSuccessful($result);
        self::assertStringContainsString(
            'documents already present',
            $result->getDisplay(),
        );
        self::assertStringContainsString(
            'minutes already present',
            $result->getDisplay(),
        );
        self::assertStringContainsString(
            'reference selections already present',
            $result->getDisplay(),
        );
        self::assertSame(
            $documents,
            $this->entityManager->getRepository(MeetingDocument::class)->count(['meeting' => $meeting]),
        );
        self::assertSame(
            $points,
            $this->entityManager->getRepository(MeetingPoint::class)->count(['meeting' => $meeting]),
        );
        self::assertSame(
            $selections,
            $this->entityManager->getRepository(MeetingReferenceSelection::class)->count(['meeting' => $meeting]),
        );
        self::assertCount(
            1,
            $meeting->getMinutes()?->getVersions() ?? [],
        );
    }

    /**
     * Minutes are one per meeting on their primary key, so a meeting the board has since given minutes of its own
     * cannot take the legacy set as well; the newer of the two is the one that is there.
     */
    public function testLeavesMinutesTheBoardUploadedItself(): void
    {
        $meeting = $this->oldestAlvMeetings()[0];

        $this->legacyFile('aa/notulen.pdf');
        $this->legacyMinutes(
            $meeting,
            'aa/notulen.pdf',
        );

        $minutes = new MeetingMinutes();
        $minutes->setMeeting($meeting);
        $this->entityManager->persist($minutes);

        $version = new MeetingMinutesVersion();
        $version->setMinutes($minutes);
        $version->setVersionLabel('v2.0');
        $version->setPath('meetings/minutes/aa/uploaded.pdf');
        $this->entityManager->persist($version);
        $this->entityManager->flush();

        $this->assertCommandIsSuccessful($this->migrateMeetings());

        $versions = $meeting->getMinutes()?->getVersions()->getValues() ?? [];
        self::assertCount(
            1,
            $versions,
        );
        self::assertSame(
            'v2.0',
            $versions[0]->getVersionLabel(),
        );
    }

    private function migrateMeetings(bool $dryRun = false): ExecutionResult
    {
        $input = [
            '--meetings' => true,
            '--source-dir' => $this->sourceDir,
        ];

        if ($dryRun) {
            $input['--dry-run'] = true;
        }

        return static::runCommand(
            'app:storage:migrate',
            $input,
            interactive: false,
        );
    }

    /**
     * The two oldest seeded ALVs; the calendar moves with the run date, so they are resolved, not assumed.
     *
     * @return array{0: Meeting, 1: Meeting}
     */
    private function oldestAlvMeetings(): array
    {
        // By date rather than by number: what this needs is the two earliest meetings, and the seed's numbering does
        // not run in step with its dates -- the meetings a board's history is written at go back further than the
        // low-numbered ones. The versions these pin are ordered by when the meeting was, so date is what has to
        // decide.
        $meetings = $this->entityManager->getRepository(Meeting::class)->findBy(
            ['type' => MeetingTypes::ALV],
            ['date' => 'ASC'],
            2,
        );
        self::assertCount(
            2,
            $meetings,
        );

        return [
            $meetings[0],
            $meetings[1],
        ];
    }

    private function legacyDocument(
        Meeting $meeting,
        string $name,
        string $path,
    ): void {
        $document = new LegacyMeetingDocument();
        $document->setMeeting($meeting);
        $document->setName($name);
        $document->setPath($path);

        $this->entityManager->persist($document);
    }

    private function legacyMinutes(
        Meeting $meeting,
        string $path,
    ): void {
        $minutes = new LegacyMeetingMinutes();
        $minutes->setMeeting($meeting);
        $minutes->setPath($path);

        $this->entityManager->persist($minutes);
    }

    private function legacyFile(string $path): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir(dirname($this->sourceDir . '/' . $path));
        $filesystem->dumpFile(
            $this->sourceDir . '/' . $path,
            sprintf(
                "%%PDF-1.4\n%% %s\n%%%%EOF\n",
                $path,
            ),
        );
    }
}
