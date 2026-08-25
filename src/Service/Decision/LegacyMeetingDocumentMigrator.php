<?php

declare(strict_types=1);

namespace App\Service\Decision;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Decision\LegacyMeetingDocument;
use App\Entity\Decision\Meeting;
use App\Entity\Decision\MeetingDocument;
use App\Entity\Decision\MeetingDocumentVersion;
use App\Entity\Decision\MeetingMinutes;
use App\Entity\Decision\MeetingMinutesVersion;
use App\Entity\Decision\MeetingPoint;
use App\Entity\Decision\MeetingReferenceSelection;
use App\Entity\Decision\ReferenceDocument;
use App\Entity\Decision\ReferenceDocumentVersion;
use App\Repository\Decision\LegacyMeetingDocumentRepository;
use App\Repository\Decision\LegacyMeetingMinutesRepository;
use App\Repository\Decision\MeetingReferenceSelectionRepository;
use App\Repository\Decision\ReferenceDocumentRepository;
use App\Service\Application\FileStorage;
use App\Service\Application\FileStorageException;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function count;
use function intval;
use function is_dir;
use function is_file;
use function ksort;
use function max;
use function sprintf;
use function str_starts_with;
use function strval;
use function usort;

/**
 * One-shot migration of the legacy flat meeting documents (preserved as `LegacyMeetingDocument` and
 * `LegacyMeetingMinutes` by the schema migration) into the agenda-point/version model. Names are interpreted by
 * {@see LegacyDocumentNameParser}: parsed point prefixes become agenda points, rows differing only in their version
 * or date suffix collapse into one document with multiple versions, and known recurring documents move into the
 * reference library with a per-meeting pinned version. Anything unparseable stays a meeting-level document under its
 * original name.
 *
 * The migration is idempotent per row rather than all-or-nothing: a legacy row whose counterpart is already there —
 * because an earlier run made it, or because the board made it by hand afterwards — is skipped and counted, and the
 * rest is migrated around it. A meeting that already has agenda points and minutes therefore still receives the
 * legacy documents it is missing, and running the phase twice creates nothing twice. That matters because the phase
 * is the only thing that carries meeting files out of the legacy pool, so it has to stay runnable long after the
 * other phases have settled.
 *
 * `--dry-run` computes and reports the same migration without writing to the database or the file storage. Files are
 * read from the legacy content-addressed layout, which the storage migration never covered for meeting documents.
 */
class LegacyMeetingDocumentMigrator
{
    private const array REFERENCE_NAMES = [
        'eternal-memorandum-and-decision-list' => 'Eternal Memorandum and Decision List',
        'eternal-memorandum' => 'Eternal Memorandum',
        'eternal-decision-list' => 'Eternal Decision List',
        'scenarios-and-procedures' => 'Scenarios and Procedures',
        'summaries-of-old-gmms' => 'Summaries of old GMMs',
        'financial-definition-list' => 'Financial Definition List',
        'translation-template-decision-list' => 'Translation Template Decision List',
    ];

    private bool $dryRun = false;
    private string $sourceDir = '';

    /** @var list<string> */
    private array $missingFiles = [];

    /** @var list<string> */
    private array $rejectedFiles = [];

    /** @var list<string> */
    private array $unparsedNames = [];

    /** @var array<string, int> */
    private array $counters = [];

    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly LegacyMeetingDocumentRepository $legacyDocuments,
        private readonly LegacyMeetingMinutesRepository $legacyMinutes,
        private readonly ReferenceDocumentRepository $referenceDocuments,
        private readonly MeetingReferenceSelectionRepository $referenceSelections,
        private readonly FileStorage $fileStorage,
        private readonly LegacyDocumentNameParser $parser,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Rebuild the agenda-point/version model from the legacy flat rows. Returns false when it refused to run.
     */
    public function migrate(
        SymfonyStyle $io,
        bool $dryRun,
        string $sourceDir,
    ): bool {
        $this->dryRun = $dryRun;
        $this->sourceDir = str_starts_with(
            $sourceDir,
            '/',
        )
            ? $sourceDir
            : $this->projectDir . '/' . $sourceDir;

        // Without the pool every row would report its file as missing and the phase would look like it had nothing
        // to do, which is exactly how the meeting files came to be left behind.
        if (!is_dir($this->sourceDir)) {
            $io->error(sprintf(
                'The legacy pool "%s" is not a directory; name where it is mounted with --source-dir.',
                $this->sourceDir,
            ));

            return false;
        }

        $rows = $this->legacyDocuments->findAllOrderedById();

        $referenceRows = [];
        $documentRows = [];
        foreach ($rows as $row) {
            $this->count('legacy document rows read');

            $parsed = $this->parser->parse($row->getName());

            if (null !== $parsed->referenceKey) {
                $referenceRows[$parsed->referenceKey][] = [
                    $row,
                    $parsed,
                ];
                continue;
            }

            if (
                null === $parsed->pointNumber
                && null === $parsed->versionLabel
                && null === $parsed->versionDate
            ) {
                $this->unparsedNames[] = $row->getName();
            }

            $meeting = $row->getMeeting();
            $documentRows[$meeting->getType()->value][$meeting->getNumber()][] = [
                $row,
                $parsed,
            ];
        }

        $this->migrateReferences($referenceRows);
        $this->migrateDocuments($documentRows);
        $this->migrateMinutes();

        if (!$this->dryRun) {
            $this->entityManager->flush();
        }

        $this->report($io);

        return true;
    }

    /**
     * The legacy rows the new model has nothing for: minutes on a meeting that has none, and documents on a meeting
     * that has none at all. Whoever is deciding whether the legacy pool can go needs a number that is zero before it
     * can, and these files are copied out of the pool rather than hardlinked, so the pool is their only other copy.
     *
     * @return array{documents: int, minutes: int}
     */
    public function pending(): array
    {
        $documents = 0;
        foreach ($this->legacyDocuments->findAllOrderedById() as $row) {
            if (!$row->getMeeting()->getDocuments()->isEmpty()) {
                continue;
            }

            $documents++;
        }

        $minutes = 0;
        foreach ($this->legacyMinutes->findAll() as $row) {
            if (null !== $row->getMeeting()->getMinutes()) {
                continue;
            }

            $minutes++;
        }

        return [
            'documents' => $documents,
            'minutes' => $minutes,
        ];
    }

    /**
     * Every meeting that shipped a recurring document gets a selection pinned to the exact version it shipped. The
     * legacy paths are content addressed, so identical paths are identical files and become one library version,
     * attributed to the first meeting that shipped them.
     *
     * @param array<string, list<array{LegacyMeetingDocument, ParsedLegacyName}>> $referenceRows
     */
    private function migrateReferences(array $referenceRows): void
    {
        foreach ($referenceRows as $key => $rows) {
            usort(
                $rows,
                static fn (array $a, array $b): int => [
                    $a[0]->getMeeting()->getDate(),
                    $a[0]->getId(),
                ]
                    <=> [
                        $b[0]->getMeeting()->getDate(),
                        $b[0]->getId(),
                    ],
            );

            $storedByPath = [];
            foreach ($rows as [$row]) {
                $legacyPath = $row->getPath();
                if (
                    array_key_exists(
                        $legacyPath,
                        $storedByPath,
                    )
                ) {
                    continue;
                }

                $storedByPath[$legacyPath] = $this->store(
                    $legacyPath,
                    StorageNamespace::ReferenceDocument,
                    $row->getName(),
                );
            }

            $survivors = count(array_filter(
                $storedByPath,
                static fn (?string $storedPath): bool => null !== $storedPath,
            ));
            if (0 === $survivors) {
                $this->count('reference documents without surviving files');
                continue;
            }

            // The library is not per meeting, so its document is matched by the name the migration gives it. Its
            // versions are content addressed, which makes the stored path the identity of a version.
            $document = $this->referenceDocuments->findOneBy(['name' => self::REFERENCE_NAMES[$key]]);
            $versionByPath = [];

            if (null === $document) {
                $document = new ReferenceDocument();
                $document->setName(self::REFERENCE_NAMES[$key]);
                $this->persist($document);
                $this->count('reference documents');
            } else {
                $this->count('reference documents already present');

                foreach ($document->getVersions() as $version) {
                    $versionByPath[$version->getPath()] = $version;
                }
            }

            $selectionByMeeting = [];
            $keptSelections = $this->existingSelectionKeys($document);
            $sequence = count($versionByPath);
            foreach ($rows as [$row, $parsed]) {
                $storedPath = $storedByPath[$row->getPath()];
                if (null === $storedPath) {
                    continue;
                }

                if (!isset($versionByPath[$storedPath])) {
                    $sequence++;
                    $version = new ReferenceDocumentVersion();
                    $version->setReferenceDocument($document);
                    $version->setVersionLabel($parsed->versionLabel ?? 'v' . $sequence);
                    $version->setPath($storedPath);
                    $version->setUploadedAt($this->uploadedAt(
                        $parsed,
                        $row->getCreatedAt(),
                    ));
                    $this->persist($version);
                    $this->count('reference document versions');

                    $versionByPath[$storedPath] = $version;
                }

                $meeting = $row->getMeeting();
                $meetingKey = $meeting->getType()->value . '|' . $meeting->getNumber();

                // A selection that is already there is the board's own choice of version; the migration leaves it
                // alone rather than pinning the version the meeting once shipped over it.
                if (isset($keptSelections[$meetingKey])) {
                    $this->count('reference selections already present');
                    continue;
                }

                // A meeting can have shipped the document twice; the newest shipment wins the pin.
                if (isset($selectionByMeeting[$meetingKey])) {
                    $selectionByMeeting[$meetingKey]->setPinnedVersion($versionByPath[$storedPath]);
                    continue;
                }

                $selection = new MeetingReferenceSelection();
                $selection->setMeeting($meeting);
                $selection->setReferenceDocument($document);
                $selection->setPinnedVersion($versionByPath[$storedPath]);
                $this->persist($selection);
                $this->count('reference selections');

                $selectionByMeeting[$meetingKey] = $selection;
            }
        }
    }

    /**
     * @param array<string, array<int, list<array{LegacyMeetingDocument, ParsedLegacyName}>>> $documentRows
     */
    private function migrateDocuments(array $documentRows): void
    {
        foreach ($documentRows as $byNumber) {
            foreach ($byNumber as $rows) {
                $meeting = $rows[0][0]->getMeeting();

                // The versions of one document share an agenda point and a normalised base name.
                $groups = [];
                foreach ($rows as $entry) {
                    $groups[($entry[1]->pointNumber ?? '~') . '|' . $entry[1]->groupKey][] = $entry;
                }

                // What the meeting already holds: an earlier run of this phase, or the board working in the new model
                // since. Both are kept, and the migration fills in around them rather than doubling them.
                $points = $this->existingPoints($meeting);
                $pointPosition = $this->nextPosition($points);
                $taken = $this->existingDocumentKeys($meeting);
                $meetingLevelPosition = $this->nextPosition($this->meetingLevelDocuments($meeting));

                $survivingGroups = [];
                foreach ($groups as $entries) {
                    usort(
                        $entries,
                        static fn (array $a, array $b): int => $a[0]->getId() <=> $b[0]->getId(),
                    );

                    // Checked twice: once on the name the newest row carries, so a group that is already there is
                    // not hashed and copied for nothing, and once on the name the document actually ends up with,
                    // which is the newest row whose file survived.
                    if (isset($taken[$this->keyOf($entries[count($entries) - 1][1])])) {
                        $this->count('documents already present');
                        continue;
                    }

                    $stored = [];
                    foreach ($entries as [$row, $parsed]) {
                        $storedPath = $this->store(
                            $row->getPath(),
                            StorageNamespace::MeetingDocument,
                            $row->getName(),
                            $meeting->getStorageScope(),
                        );
                        if (null === $storedPath) {
                            continue;
                        }

                        $stored[] = [
                            $row,
                            $parsed,
                            $storedPath,
                        ];
                    }

                    if ([] === $stored) {
                        $this->count('documents without surviving files');
                        continue;
                    }

                    $key = $this->keyOf($stored[count($stored) - 1][1]);
                    if (isset($taken[$key])) {
                        $this->count('documents already present');
                        continue;
                    }

                    $taken[$key] = true;
                    $survivingGroups[] = $stored;
                }

                // Points only exist for documents that survived; numeric string keys collapse to
                // integers, so a plain key sort is numeric. The board can retitle and reorder later.
                $pointNumbers = [];
                foreach ($survivingGroups as $stored) {
                    $pointNumber = $stored[0][1]->pointNumber;
                    if (null === $pointNumber) {
                        continue;
                    }

                    $pointNumbers[intval($pointNumber)] = true;
                }

                ksort($pointNumbers);

                foreach (array_keys($pointNumbers) as $pointNumber) {
                    if (isset($points[$pointNumber])) {
                        continue;
                    }

                    $point = new MeetingPoint();
                    $point->setMeeting($meeting);
                    $point->setNumber(strval($pointNumber));
                    $point->setTitle('');
                    $point->setDisplayPosition($pointPosition);
                    $pointPosition++;

                    $this->persist($point);
                    $this->count('agenda points');
                    $points[$pointNumber] = $point;
                }

                foreach ($survivingGroups as $stored) {
                    $newest = $stored[count($stored) - 1];
                    $pointNumber = $newest[1]->pointNumber;

                    $document = new MeetingDocument();
                    $document->setMeeting($meeting);
                    $document->setPoint(null === $pointNumber ? null : $points[intval($pointNumber)]);
                    $document->setName($newest[1]->baseName);
                    $document->setDisplayPosition(
                        null === $pointNumber
                            ? $meetingLevelPosition
                            : $newest[0]->getDisplayPosition(),
                    );
                    $this->persist($document);
                    $this->count('documents');

                    if (null === $pointNumber) {
                        $meetingLevelPosition++;
                    }

                    $sequence = 0;
                    foreach ($stored as [$row, $parsed, $storedPath]) {
                        $sequence++;
                        $version = new MeetingDocumentVersion();
                        $version->setDocument($document);
                        $version->setVersionLabel($parsed->versionLabel ?? 'v' . $sequence);
                        $version->setPath($storedPath);
                        $version->setUploadedAt($this->uploadedAt(
                            $parsed,
                            $row->getCreatedAt(),
                        ));
                        $this->persist($version);
                        $this->count('document versions');
                    }
                }
            }
        }
    }

    private function migrateMinutes(): void
    {
        $rows = $this->legacyMinutes->findAll();

        foreach ($rows as $row) {
            $meeting = $row->getMeeting();

            // Minutes are one per meeting on their primary key, so a meeting that already has them cannot receive
            // the legacy set as well; whatever is there is newer than what is being migrated.
            if (null !== $meeting->getMinutes()) {
                $this->count('minutes already present');
                continue;
            }

            $described = sprintf(
                'Minutes %s %d',
                $meeting->getType()->value,
                $meeting->getNumber(),
            );

            $storedPath = $this->store(
                $row->getPath(),
                StorageNamespace::MeetingMinutes,
                $described,
                $meeting->getStorageScope(),
            );
            if (null === $storedPath) {
                continue;
            }

            $minutes = new MeetingMinutes();
            $minutes->setMeeting($meeting);
            $this->persist($minutes);

            $version = new MeetingMinutesVersion();
            $version->setMinutes($minutes);
            $version->setVersionLabel('v1.0');
            $version->setPath($storedPath);
            $version->setUploadedAt(
                intval($row->getUpdatedAt()->format('Y')) >= 2000
                    ? DateTime::createFromInterface($row->getUpdatedAt())
                    : null,
            );
            $this->persist($version);
            $this->count('minutes');
        }
    }

    /**
     * The agenda points a meeting already has, by their number. A number PHP reads as an integer becomes an integer
     * key, which is how a point this migration numbered is found again by {@see intval()} of a parsed prefix.
     *
     * @return array<int|string, MeetingPoint>
     */
    private function existingPoints(Meeting $meeting): array
    {
        $points = [];

        foreach ($meeting->getPoints() as $point) {
            $points[$point->getNumber()] = $point;
        }

        return $points;
    }

    /**
     * The documents a meeting already has, keyed the way {@see documentKey()} identifies them.
     *
     * @return array<string, true>
     */
    private function existingDocumentKeys(Meeting $meeting): array
    {
        $taken = [];

        foreach ($meeting->getDocuments() as $document) {
            $taken[$this->documentKey(
                $document->getPoint()?->getNumber(),
                $document->getName(),
            )] = true;
        }

        return $taken;
    }

    /**
     * The documents a meeting already has that hang under no agenda point; they are the ones whose display position
     * this migration hands out.
     *
     * @return list<MeetingDocument>
     */
    private function meetingLevelDocuments(Meeting $meeting): array
    {
        $documents = [];

        foreach ($meeting->getDocuments() as $document) {
            if (null !== $document->getPoint()) {
                continue;
            }

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * The display position something added now would take, after everything already ordered.
     *
     * @param iterable<MeetingDocument|MeetingPoint> $ordered
     */
    private function nextPosition(iterable $ordered): int
    {
        $position = 0;

        foreach ($ordered as $item) {
            $position = max(
                $position,
                $item->getDisplayPosition() + 1,
            );
        }

        return $position;
    }

    /**
     * The meetings that already have a selection of a library document, by the key the migration matches them on. A
     * document this run has just made has none, and cannot be asked for them before it has an identifier.
     *
     * @return array<string, true>
     */
    private function existingSelectionKeys(ReferenceDocument $document): array
    {
        if (null === $document->getId()) {
            return [];
        }

        $keys = [];
        foreach ($this->referenceSelections->findBy(['referenceDocument' => $document]) as $selection) {
            $meeting = $selection->getMeeting();
            $keys[$meeting->getType()->value . '|' . $meeting->getNumber()] = true;
        }

        return $keys;
    }

    /**
     * The key of the document one parsed legacy name would produce.
     */
    private function keyOf(ParsedLegacyName $parsed): string
    {
        return $this->documentKey(
            $parsed->pointNumber,
            $parsed->baseName,
        );
    }

    /**
     * How a document is recognised as one this migration would produce again: its agenda point, numbered the way the
     * migration numbers points, and the name it is displayed under.
     */
    private function documentKey(
        ?string $pointNumber,
        string $name,
    ): string {
        $point = null === $pointNumber
            ? '~'
            : strval(intval($pointNumber));

        return $point . '|' . $name;
    }

    private function store(
        string $legacyPath,
        StorageNamespace $namespace,
        string $describedAs,
        ?string $scope = null,
    ): ?string {
        $absolutePath = $this->sourceDir . '/' . $legacyPath;

        if (!is_file($absolutePath)) {
            $this->missingFiles[] = sprintf(
                '%s (%s)',
                $legacyPath,
                $describedAs,
            );

            return null;
        }

        if ($this->dryRun) {
            return 'dry-run/' . $legacyPath;
        }

        try {
            return $this->fileStorage->store(
                $namespace,
                $absolutePath,
                $scope,
            )->path;
        } catch (FileStorageException $e) {
            $this->rejectedFiles[] = sprintf(
                '%s (%s): %s',
                $legacyPath,
                $describedAs,
                $e->getMessage(),
            );

            return null;
        }
    }

    /**
     * The oldest legacy imports carry a placeholder timestamp far in the past, which really means "unknown".
     */
    private function uploadedAt(
        ParsedLegacyName $parsed,
        DateTime $createdAt,
    ): ?DateTime {
        if (null !== $parsed->versionDate) {
            return DateTime::createFromImmutable($parsed->versionDate);
        }

        if (intval($createdAt->format('Y')) >= 2000) {
            return DateTime::createFromInterface($createdAt);
        }

        return null;
    }

    private function persist(object $entity): void
    {
        if ($this->dryRun) {
            return;
        }

        $this->entityManager->persist($entity);
    }

    private function count(string $key): void
    {
        $this->counters[$key] = ($this->counters[$key] ?? 0) + 1;
    }

    private function report(SymfonyStyle $io): void
    {
        $io->title($this->dryRun ? 'Legacy meeting document migration (dry run)' : 'Legacy meeting document migration');

        $rows = [];
        foreach ($this->counters as $key => $value) {
            $rows[] = [
                $key,
                $value,
            ];
        }

        $rows[] = [
            'missing files',
            count($this->missingFiles),
        ];
        $rows[] = [
            'rejected files',
            count($this->rejectedFiles),
        ];
        $rows[] = [
            'names kept verbatim as meeting-level documents',
            count($this->unparsedNames),
        ];

        // Not $io->table(): that grabs a console section, which the test harness output does not support.
        new Table($io)
            ->setHeaders(['What', 'Count'])
            ->setRows($rows)
            ->render();

        if ($io->isVerbose()) {
            $this->listing(
                $io,
                'Names kept verbatim',
                $this->unparsedNames,
            );
            $this->listing(
                $io,
                'Missing files',
                $this->missingFiles,
            );
            $this->listing(
                $io,
                'Rejected files',
                $this->rejectedFiles,
            );
        }

        if ($this->dryRun) {
            $io->note('Dry run: nothing was written.');

            return;
        }

        // Meeting files are copied out of the legacy pool rather than hardlinked into place like the other phases,
        // so until this reports nothing left behind the pool is still the only copy of them.
        $leftBehind = count($this->missingFiles) + count($this->rejectedFiles);
        if ($leftBehind > 0) {
            $io->warning(sprintf(
                'Migration complete, but %d legacy rows could not be read from "%s" and have no counterpart. '
                . 'Keep the legacy pool and run this phase again once they can be read.',
                $leftBehind,
                $this->sourceDir,
            ));

            return;
        }

        $io->success('Migration complete; every legacy meeting document and set of minutes now has a copy of its '
            . 'file in the data/ layout.');
    }

    /**
     * @param list<string> $items
     */
    private function listing(
        SymfonyStyle $io,
        string $title,
        array $items,
    ): void {
        if ([] === $items) {
            return;
        }

        $io->section($title);
        $io->listing($items);
    }
}
