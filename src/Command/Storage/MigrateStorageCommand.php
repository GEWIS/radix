<?php

declare(strict_types=1);

namespace App\Command\Storage;

use App\Entity\Application\Enums\StorageNamespace;
use App\Entity\Career\CompanyBannerPackage;
use App\Entity\Career\CompanyRevision;
use App\Entity\Decision\OrganInformationRevision;
use App\Entity\Education\CourseDocument;
use App\Entity\Frontpage\FrontpageLocalisedText;
use App\Entity\Photo\Album;
use App\Entity\Photo\Photo;
use App\Repository\Career\CompanyBannerPackageRepository;
use App\Repository\Career\CompanyRevisionRepository;
use App\Repository\Decision\OrganInformationRevisionRepository;
use App\Repository\Education\CourseDocumentRepository;
use App\Repository\Photo\AlbumRepository;
use App\Repository\Photo\PhotoRepository;
use App\Service\Application\StorageMigrationJournal;
use App\Service\Decision\LegacyMeetingDocumentMigrator;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use JsonException;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function array_key_exists;
use function array_map;
use function assert;
use function basename;
use function copy;
use function count;
use function date;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function implode;
use function in_array;
use function intval;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function json_encode;
use function link;
use function mkdir;
use function preg_replace_callback;
use function sort;
use function sprintf;
use function str_starts_with;
use function strval;
use function trim;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * One-shot migration from the legacy Laminas file layout to the content-addressed layout served by
 * {@see \App\Service\Application\FileStorage}.
 *
 * Two layouts coexist during the cut-over:
 *  - LEGACY: everything lived under `public/data/`. Hashed assets (album photo originals, generated album covers and
 *    organ cover/thumbnail images) were stored at `public/data/{2ch}/{rest-of-sha1}.{ext}`; per-company assets (the
 *    company logo and banner-package image) at `public/data/company/{companyId}/{2ch}/{rest-of-sha1}.{ext}`. The DB
 *    columns hold the path relative to `public/data/`.
 *  - NEW: everything lives under `data/` (never web-reachable), partitioned per {@see StorageNamespace}, e.g.
 *    `data/photos/albums/{2ch}/`, `data/photos/covers/`, `data/organs/images/`, `data/career/{companyId}/images/`,
 *    `data/education/courses/{courseCode}/`.
 *
 * The migration keeps the existing (sha1) filenames (it never re-hashes), so it is instant and adds no disk. It runs
 * in two independent, re-runnable phases:
 *  - `--files` hardlinks each legacy file into its new location (both layouts stay live; nothing is ever deleted).
 *  - `--paths` rewrites the DB path columns to the new layout (the actual switch-over), recording a rollback log.
 *
 * Both phases derive the new location from the legacy value with the exact same mapping ({@see mapLegacyPath()}), so a
 * row's rewritten path always points at a file `--files` created. `--dry-run` reports without changing anything, and
 * `--rollback` restores the DB paths from a `--paths` run's log.
 *
 * @phpstan-type StorageTarget = array{
 *     key: string,
 *     field: string,
 *     namespace: StorageNamespace,
 * }
 * @phpstan-type MigrationRow = array{
 *     key: string,
 *     entity: object,
 *     field: string,
 *     legacy: string,
 *     new: string,
 * }
 * @phpstan-type LogEntry = array{
 *     key: string,
 *     id: int,
 *     old: string,
 *     new: string,
 * }
 */
#[AsCommand(
    name: 'app:storage:migrate',
    description: 'Migrate stored files and their paths from the legacy content-addressed pool to the data/ layout.',
)]
final class MigrateStorageCommand extends Command
{
    /** Stable per-column identifiers, persisted in the rollback log so a restore never depends on class names. */
    private const string KEY_PHOTO = 'photo-original';
    private const string KEY_ALBUM_COVER = 'album-cover';
    private const string KEY_COMPANY_LOGO = 'company-logo';
    private const string KEY_COMPANY_BANNER = 'company-banner';
    private const string KEY_ORGAN_COVER = 'organ-cover';
    private const string KEY_ORGAN_THUMBNAIL = 'organ-thumbnail';
    private const string KEY_ORGAN_BANNER_SOURCE = 'organ-banner-source';
    private const string KEY_ORGAN_LOGO_SOURCE = 'organ-logo-source';
    private const string KEY_COMPANY_BANNER_PENDING = 'company-banner-pending';
    private const string KEY_COMPANY_BANNER_LOGO = 'company-banner-logo';
    private const string KEY_COURSE_DOCUMENT = 'course-document';

    /** Outcomes of a single hardlink attempt. */
    private const string LINK_LINKED = 'linked';
    private const string LINK_SKIPPED = 'skipped';
    private const string LINK_MISSING_SOURCE = 'missing';
    private const string LINK_COPIED = 'copied';
    private const string LINK_FAILED = 'failed';

    /** How many legacy-to-new pairs to show in the report, and how often a read-only pass detaches managed entities. */
    private const int SAMPLE_SIZE = 10;
    private const int CLEAR_EVERY = 500;

    /** The variant a page's images were served at when they were uploaded through the editor. */
    private const string PAGE_IMAGE_VARIANT = 'w1280';

    /** A legacy source in page content: `/data/{2ch}/{sha1 tail}.{ext}`, as the old editor wrote it. */
    private const string PAGE_IMAGE_PATTERN = '#(?:https?://[^/"\'\s]+)?/?(?:public/)?'
        . 'data/([0-9a-f]{2})/([0-9a-f]+\\.[A-Za-z0-9]+)#i';

    private StorageMigrationJournal $journal;

    private bool $retryFailed = false;

    private string $legacyRoot = '';

    public function __construct(
        #[Autowire(service: 'doctrine.orm.web_entity_manager')]
        private readonly EntityManagerInterface $entityManager,
        private readonly PhotoRepository $photoRepository,
        private readonly AlbumRepository $albumRepository,
        private readonly CompanyRevisionRepository $companyRevisionRepository,
        private readonly CompanyBannerPackageRepository $companyBannerPackageRepository,
        private readonly OrganInformationRevisionRepository $organInformationRevisionRepository,
        private readonly CourseDocumentRepository $courseDocumentRepository,
        private readonly LegacyMeetingDocumentMigrator $meetingMigrator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
                Every legacy file the merged application inherits lives in one content-addressed pool, the one
                GEWISWEB wrote: photos, company and organ images, course documents, the images embedded in custom
                pages, and the flat meeting documents and minutes. One run over that one pool migrates all of them.

                  bin/console app:storage:migrate --source-dir=/app/data/data-old

                Naming no phase runs all four, in the order they depend on each other: <info>--files</info> puts the
                files in place, <info>--paths</info> switches the stored path columns over to them, <info>--pages</info>
                rewrites the sources embedded in page content, and <info>--meetings</info> rebuilds the flat meeting
                documents into the agenda-point and version model.

                The run is resumable. Each item is journalled as it commits, so a run that is interrupted can simply be
                started again and will skip what it settled; <info>--retry-failed</info> returns to the failures alone.

                <comment>--source-dir</comment> defaults to <info>public/data</info>, which is where the pool sits in a
                development checkout. In production it arrives as an already-populated volume mounted at the storage
                root, so it has to be named.
                HELP)
            ->addOption(
                'files',
                null,
                InputOption::VALUE_NONE,
                'Hardlink the legacy files into the new data/ layout (non-destructive, both layouts stay live).',
            )
            ->addOption(
                'paths',
                null,
                InputOption::VALUE_NONE,
                'Rewrite the stored path columns from the legacy layout to the new one (the switch-over).',
            )
            ->addOption(
                'pages',
                null,
                InputOption::VALUE_NONE,
                'Rewrite the legacy image sources embedded in custom page content.',
            )
            ->addOption(
                'meetings',
                null,
                InputOption::VALUE_NONE,
                'Rebuild the legacy flat meeting documents into the agenda-point/version model.',
            )
            ->addOption(
                'rollback',
                null,
                InputOption::VALUE_NONE,
                'With --paths: restore the stored paths from a previous run using its rollback log.',
            )
            ->addOption(
                'journal',
                null,
                InputOption::VALUE_REQUIRED,
                'Where to record what has been done, so an interrupted run can be resumed.',
                'var/storage-migration.jsonl',
            )
            ->addOption(
                'retry-failed',
                null,
                InputOption::VALUE_NONE,
                'Attempt the items the journal records as failed again, instead of skipping everything it recorded.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'With --meetings: run even when migrated meeting documents already exist.',
            )
            ->addOption(
                'source-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'The directory holding the legacy content-addressed files, absolute or relative to the project.',
                'public/data',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would happen without changing anything.',
            )
            ->addOption(
                'batch-size',
                null,
                InputOption::VALUE_REQUIRED,
                'Flush and clear the entity manager every N path rewrites.',
                '500',
            )
            ->addOption(
                'log',
                null,
                InputOption::VALUE_REQUIRED,
                'With --rollback: the log file to restore from (defaults to the most recent one).',
            );
    }

    #[Override]
    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $ui = new SymfonyStyle(
            $input,
            $output,
        );

        $rollback = true === $input->getOption('rollback');
        $dryRun = true === $input->getOption('dry-run');
        $this->retryFailed = true === $input->getOption('retry-failed');

        $journalPath = $this->stringOption(
            $input,
            'journal',
        ) ?? 'var/storage-migration.jsonl';
        $this->journal = new StorageMigrationJournal(str_starts_with(
            $journalPath,
            '/',
        )
            ? $journalPath
            : $this->projectDir . '/' . $journalPath);

        $sourceDir = $this->stringOption(
            $input,
            'source-dir',
        ) ?? 'public/data';
        $this->legacyRoot = str_starts_with(
            $sourceDir,
            '/',
        )
            ? $sourceDir
            : $this->projectDir . '/' . $sourceDir;

        // Naming no phase runs all of them, in the order they depend on each other: the files have to be in place
        // before anything points at them, and the meeting rebuild reads the legacy pool directly.
        $asked = [
            'files' => true === $input->getOption('files'),
            'paths' => true === $input->getOption('paths'),
            'pages' => true === $input->getOption('pages'),
            'meetings' => true === $input->getOption('meetings'),
        ];
        if (
            !in_array(
                true,
                $asked,
                true,
            )
        ) {
            $asked = [
                'files' => true,
                'paths' => true,
                'pages' => true,
                'meetings' => true,
            ];
        }

        if (
            $rollback
            && (
                $asked['files']
                || $asked['pages']
                || $asked['meetings']
            )
        ) {
            $ui->error('--rollback only applies to --paths; the other phases are non-destructive or have no log.');

            return Command::FAILURE;
        }

        if ($rollback) {
            if (
                !$this->confirmDestructive(
                    $ui,
                    $input,
                    $dryRun,
                    'restore the stored paths from the rollback log',
                )
            ) {
                return Command::SUCCESS;
            }

            return $this->rollback(
                $ui,
                $dryRun,
                $this->stringOption(
                    $input,
                    'log',
                ),
            );
        }

        $batchSize = $this->batchSize(
            $ui,
            $input,
        );
        if (null === $batchSize) {
            return Command::FAILURE;
        }

        if (
            ($asked['paths'] || $asked['pages'] || $asked['meetings'])
            && !$this->confirmDestructive(
                $ui,
                $input,
                $dryRun,
                'migrate the stored files and everything that points at them',
            )
        ) {
            return Command::SUCCESS;
        }

        if ($asked['files']) {
            $this->migrateFiles(
                $ui,
                $dryRun,
            );
        }

        if ($asked['paths']) {
            $this->migratePaths(
                $ui,
                $dryRun,
                $batchSize,
            );
        }

        if ($asked['pages']) {
            $this->migratePages(
                $ui,
                $dryRun,
            );
        }

        $refused = false;
        if ($asked['meetings']) {
            $refused = !$this->meetingMigrator->migrate(
                $ui,
                $dryRun,
                true === $input->getOption('force'),
                $this->legacyRoot,
            );
        }

        $this->reportJournal($ui);

        // The meeting rebuild refuses to run over documents it has already made, which is a reason to stop rather
        // than a phase that quietly did nothing.
        return $refused
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * Rewrite the legacy image sources embedded in custom page content, and link the files they name into the page
     * namespace.
     *
     * The editor used to write `<img src="/data/{2ch}/{name}">` straight at the old public directory. Nothing serves
     * that any more, and the sanitiser drops an `<img>` whose source does not address the image pipeline, so every one
     * of these is a picture that has already stopped appearing. They are rewritten to the pipeline URL for the same
     * file under the page namespace.
     */
    private function migratePages(
        SymfonyStyle $ui,
        bool $dryRun,
    ): void {
        $ui->section($dryRun ? 'Rewriting page content (dry run)' : 'Rewriting page content');

        $directory = StorageNamespace::PageImage->directory();
        $rewritten = 0;
        $sources = 0;
        $missing = 0;

        // DQL rather than a repository: the localised text has no repository of its own, and the generic a bare
        // getRepository() infers is not one static analysis can follow.
        $texts = $this->entityManager
            ->createQuery(sprintf('SELECT t FROM %s t', FrontpageLocalisedText::class))
            ->toIterable();

        foreach ($texts as $text) {
            assert($text instanceof FrontpageLocalisedText);

            $item = $this->itemKey(
                'pages',
                'page-content',
                $text,
            );
            if (
                $this->journal->isSettled(
                    $item,
                    $this->retryFailed,
                )
            ) {
                continue;
            }

            $before = [
                $text->getValueEN(),
                $text->getValueNL(),
            ];
            $found = 0;
            $absent = 0;

            $rewrite = function (?string $value) use ($directory, &$found, &$absent, $dryRun): ?string {
                if (null === $value) {
                    return null;
                }

                return preg_replace_callback(
                    self::PAGE_IMAGE_PATTERN,
                    function (array $match) use ($directory, &$found, &$absent, $dryRun): string {
                        ++$found;

                        $legacy = $match[1] . '/' . $match[2];
                        $new = $directory . '/' . $match[2];

                        $status = $dryRun
                            ? $this->classifyLink(
                                $this->legacyRoot() . '/' . $legacy,
                                $this->newRoot() . '/' . $new,
                            )
                            : $this->linkFile(
                                $this->legacyRoot() . '/' . $legacy,
                                $this->newRoot() . '/' . $new,
                            );

                        if (
                            self::LINK_MISSING_SOURCE === $status
                            || self::LINK_FAILED === $status
                        ) {
                            ++$absent;
                        }

                        // Rewritten whether or not the file was there: the page has to stop naming a location that
                        // nothing serves, and a picture that is missing is missing either way.
                        return '/img/' . self::PAGE_IMAGE_VARIANT . '/' . $new;
                    },
                    $value,
                );
            };

            $after = [
                $rewrite($before[0]),
                $rewrite($before[1]),
            ];

            if (0 === $found) {
                continue;
            }

            $sources += $found;
            $missing += $absent;
            ++$rewritten;

            if ($dryRun) {
                continue;
            }

            $text->updateValues(
                $after[0],
                $after[1],
            );
            $this->entityManager->flush();
            $this->journal->record(
                $item,
                $absent > 0 ? StorageMigrationJournal::MISSING_FILE : StorageMigrationJournal::DONE,
                sprintf(
                    '%d source(s), %d without a file',
                    $found,
                    $absent,
                ),
            );
        }

        $this->entityManager->clear();

        $ui->success(sprintf(
            '%s %d image source(s) across %d text(s); %d had no file behind them.',
            $dryRun ? 'Would rewrite' : 'Rewrote',
            $sources,
            $rewritten,
            $missing,
        ));
    }

    /**
     * What the journal has to say once every phase has run.
     */
    private function reportJournal(SymfonyStyle $ui): void
    {
        $tally = $this->journal->tally();
        if ([] === $tally) {
            return;
        }

        $ui->section('Journal');
        $ui->writeln(sprintf('Recorded in %s:', $this->journal->path()));

        foreach ($tally as $outcome => $count) {
            $ui->writeln(sprintf('  %-14s %d', $outcome, $count));
        }

        if (
            !array_key_exists(
                StorageMigrationJournal::FAILED,
                $tally,
            )
        ) {
            return;
        }

        $ui->warning('Run again with --retry-failed to attempt the failed items once more.');
    }

    /**
     * A stable name for one unit of work, so a resumed run knows what it already settled. The entity's own identifier
     * is used rather than its path: two rows can name the same file, and skipping the second would leave it behind.
     */
    private function itemKey(
        string $phase,
        string $key,
        object $entity,
    ): string {
        $identifiers = $this->entityManager
            ->getClassMetadata($entity::class)
            ->getIdentifierValues($entity);

        return $phase . ':' . $key . ':' . implode(
            '-',
            array_map(
                static fn (mixed $value): string => strval($value),
                $identifiers,
            ),
        );
    }

    /**
     * Map a legacy stored path onto its location in the new layout, or return null when the value is already migrated
     * or is not a recognised legacy path (both cases are left untouched, which makes the migration idempotent). This is
     * the mapping shared by the file-linking and path-rewriting phases.
     */
    public function mapLegacyPath(
        StorageNamespace $namespace,
        string $legacyPath,
        ?string $scope = null,
    ): ?string {
        $targetDirectory = $namespace->directory($scope);

        if (StorageNamespace::CompanyImage === $namespace) {
            return $this->mapLegacyCompanyPath(
                $legacyPath,
                $targetDirectory,
            );
        }

        // The legacy value is `{2ch}/{name}.{ext}` relative to `public/data/`. The sha-named file is already unique
        // and the new layout is not sharded (photos are bounded per album), so re-root just the filename under the new
        // directory and drop the legacy bucket. A value that already equals its re-rooted form was migrated before, so
        // skip it (idempotent).
        $new = $targetDirectory . '/' . basename($legacyPath);

        return $legacyPath === $new
            ? null
            : $new;
    }

    /**
     * Map a legacy per-company path (`company/{companyId}/{shard}/{name}.{ext}`) onto the per-company career namespace,
     * preserving the sha1 shard and filename.
     */
    private function mapLegacyCompanyPath(
        string $legacyPath,
        string $targetDirectory,
    ): ?string {
        // Already migrated: it points at the new per-company career directory.
        if (
            str_starts_with(
                $legacyPath,
                'career/',
            )
        ) {
            return null;
        }

        // Two legacy shapes reach this namespace. Most company assets were written per company
        // (`company/{id}/{shard}/{name}`), but the older ones went into the same flat hashed pool as everything else
        // (`{shard}/{name}`) and are told apart only by the column they sit in. Both belong under the company's own
        // directory now, and the sha-named file is unique either way, so both flatten to the same shape.
        $tail = str_starts_with(
            $legacyPath,
            'company/',
        )
            ? $this->stripCompanyPrefix($legacyPath)
            : $legacyPath;

        if (null === $tail) {
            return null;
        }

        return $targetDirectory . '/' . basename($tail);
    }

    /**
     * Strip the `company/{companyId}/` prefix off a legacy per-company path, returning the `{shard}/{name}.{ext}` tail
     * (or null when the path does not have that shape).
     */
    private function stripCompanyPrefix(string $legacyPath): ?string
    {
        $segments = explode(
            '/',
            $legacyPath,
            3,
        );

        if (
            3 !== count($segments)
            || '' === $segments[2]
        ) {
            return null;
        }

        return $segments[2];
    }

    /**
     * Hardlink every legacy file into its new location. Idempotent: an already-present destination is skipped and a
     * missing legacy source is reported, never fatal for the run.
     */
    private function migrateFiles(
        SymfonyStyle $ui,
        bool $dryRun,
    ): int {
        $ui->section($dryRun ? 'Linking legacy files (dry run)' : 'Linking legacy files into the new layout');

        $linked = 0;
        $skipped = 0;
        $missing = 0;
        $failed = 0;
        /** @var list<array{0: string, 1: string}> $sample */
        $sample = [];
        $processed = 0;

        foreach ($this->migratableRows() as $row) {
            $source = $this->legacyRoot() . '/' . $row['legacy'];
            $destination = $this->newRoot() . '/' . $row['new'];

            // Keyed on the destination rather than the row: several rows can name one file, and linking it twice is
            // work with nothing to show for it.
            $item = 'files:' . $row['new'];
            if (
                $this->journal->isSettled(
                    $item,
                    $this->retryFailed,
                )
            ) {
                continue;
            }

            // Same classification either way; a dry run only reports it, a real run performs the link for LINK_LINKED.
            $status = $dryRun
                ? $this->classifyLink(
                    $source,
                    $destination,
                )
                : $this->linkFile(
                    $source,
                    $destination,
                );

            match ($status) {
                self::LINK_LINKED, self::LINK_COPIED => $linked++,
                self::LINK_SKIPPED => $skipped++,
                self::LINK_FAILED => $failed++,
                default => $missing++,
            };

            if (!$dryRun) {
                // A missing legacy file is settled, not failed: the file is not coming back, and the row that names it
                // still has to be rewritten by the next phase. A file that is there but could not be put in place is
                // the one thing worth trying again.
                $this->journal->record(
                    $item,
                    match ($status) {
                        self::LINK_MISSING_SOURCE => StorageMigrationJournal::MISSING_FILE,
                        self::LINK_FAILED => StorageMigrationJournal::FAILED,
                        default => StorageMigrationJournal::DONE,
                    },
                    self::LINK_LINKED === $status ? null : $row['legacy'],
                );
            }

            if (count($sample) < self::SAMPLE_SIZE) {
                $sample[] = [
                    $row['legacy'],
                    $row['new'],
                ];
            }

            // Read-only pass: periodically detach managed entities so memory stays flat over a large photo set.
            if (0 !== ++$processed % self::CLEAR_EVERY) {
                continue;
            }

            $this->entityManager->clear();
        }

        $this->entityManager->clear();

        $this->reportSample(
            $ui,
            $sample,
        );
        $ui->success(sprintf(
            '%s %d file(s); skipped %d already present; %d legacy source(s) missing; %d failed.',
            $dryRun ? 'Would place' : 'Placed',
            $linked,
            $skipped,
            $missing,
            $failed,
        ));

        return Command::SUCCESS;
    }

    /**
     * Rewrite the stored path columns to the new layout in batches, writing a rollback log as it goes.
     *
     * Each batch's log entries are appended to the log file before that batch is committed to the database (a
     * write-ahead log). If the process dies mid-run, every committed rewrite is therefore already in the log, and any
     * entry logged for a batch that was not committed is harmless because rollback() only restores a row that still
     * points at the migrated value.
     */
    private function migratePaths(
        SymfonyStyle $ui,
        bool $dryRun,
        int $batchSize,
    ): int {
        $ui->section($dryRun ? 'Rewriting stored paths (dry run)' : 'Rewriting stored paths');

        $rewritten = 0;
        $failed = 0;
        /** @var list<array{0: string, 1: string}> $sample */
        $sample = [];
        /** @var list<LogEntry> $pending */
        $pending = [];
        /** @var list<string> $batchItems */
        $batchItems = [];
        $logFile = $dryRun
            ? null
            : $this->newLogFile();
        $processed = 0;

        foreach ($this->migratableRows() as $row) {
            $item = $this->itemKey(
                'paths',
                $row['key'],
                $row['entity'],
            );
            if (
                $this->journal->isSettled(
                    $item,
                    $this->retryFailed,
                )
            ) {
                continue;
            }

            $rewritten++;

            if (count($sample) < self::SAMPLE_SIZE) {
                $sample[] = [
                    $row['legacy'],
                    $row['new'],
                ];
            }

            if (null !== $logFile) {
                $pending[] = [
                    'key' => $row['key'],
                    'id' => $this->entityId($row['entity']),
                    'old' => $row['legacy'],
                    'new' => $row['new'],
                ];
                $this->writePathField(
                    $row['entity'],
                    $row['field'],
                    $row['new'],
                );
                // Recorded before the flush that commits it. A crash in between leaves an item the next run repeats,
                // which rewrites the same value onto the same row; recording it after would risk the opposite, an
                // item committed but unrecorded and then skipped by a resumed run that believes it still has to do it.
                // Held until the batch commits: an item is only settled once the row it changed is in the database.
                $batchItems[] = $item;
            }

            if (0 !== ++$processed % $batchSize) {
                continue;
            }

            if (null !== $logFile) {
                $this->appendLog(
                    $logFile,
                    $pending,
                );
                $failed += $this->commitBatch(
                    $ui,
                    $batchItems,
                );
                $pending = [];
                $batchItems = [];
            }

            $this->entityManager->clear();
        }

        $this->reportSample(
            $ui,
            $sample,
        );

        if (null === $logFile) {
            $ui->success(sprintf('Would rewrite %d stored path(s).', $rewritten));

            return Command::SUCCESS;
        }

        // Log and commit the final partial batch.
        $this->appendLog(
            $logFile,
            $pending,
        );
        $failed += $this->commitBatch(
            $ui,
            $batchItems,
        );
        $this->entityManager->clear();

        if (0 === $rewritten) {
            $ui->success('No legacy stored paths were found; nothing to rewrite.');

            return Command::SUCCESS;
        }

        $ui->success(sprintf(
            'Rewrote %d stored path(s), %d could not be written. Rollback log written to "%s".',
            $rewritten - $failed,
            $failed,
            $logFile,
        ));

        return Command::SUCCESS;
    }

    /**
     * Commit one batch and settle its items, or record every one of them as failed and carry on.
     *
     * A batch is one flush, so a single row the database refuses takes the whole batch with it. Stopping there would
     * mean a migration that has to be restarted by hand every time it meets one bad row; recording them instead lets
     * the run finish and `--retry-failed` come back to exactly those.
     *
     * @param list<string> $items
     *
     * @return int how many items were not written
     */
    private function commitBatch(
        SymfonyStyle $ui,
        array $items,
    ): int {
        if ([] === $items) {
            return 0;
        }

        try {
            $this->entityManager->flush();
        } catch (Throwable $e) {
            foreach ($items as $item) {
                $this->journal->record(
                    $item,
                    StorageMigrationJournal::FAILED,
                    $e->getMessage(),
                );
            }

            $ui->warning(sprintf(
                '%d path(s) could not be written and were recorded as failed: %s',
                count($items),
                $e->getMessage(),
            ));

            // The unit of work still holds the changes the flush refused; they must not be retried by the next batch.
            $this->entityManager->clear();

            return count($items);
        }

        foreach ($items as $item) {
            $this->journal->record(
                $item,
                StorageMigrationJournal::DONE,
            );
        }

        return 0;
    }

    /**
     * Restore stored paths from a `--paths` rollback log. A row is only restored while it still points at the migrated
     * value, so a path changed since the migration is never clobbered.
     */
    private function rollback(
        SymfonyStyle $ui,
        bool $dryRun,
        ?string $logOption,
    ): int {
        $logFile = $this->resolveLogFile($logOption);
        if (null === $logFile) {
            $ui->error('No rollback log was found under var/storage-migration; nothing to roll back.');

            return Command::FAILURE;
        }

        $ui->section(sprintf(
            '%sRolling back stored paths from "%s"',
            $dryRun ? '(dry run) ' : '',
            $logFile,
        ));

        $restored = 0;
        $skipped = 0;
        $processed = 0;

        foreach ($this->readLog($logFile) as $entry) {
            $field = $this->fieldForKey($entry['key']);
            $entity = $this->findByKey(
                $entry['key'],
                $entry['id'],
            );

            if (
                null === $field
                || null === $entity
                || $this->readPathField(
                    $entity,
                    $field,
                ) !== $entry['new']
            ) {
                $skipped++;

                continue;
            }

            $restored++;

            if ($dryRun) {
                continue;
            }

            $this->writePathField(
                $entity,
                $field,
                $entry['old'],
            );

            if (0 !== ++$processed % self::CLEAR_EVERY) {
                continue;
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        $ui->success(sprintf(
            '%s %d stored path(s); skipped %d (missing, unknown, or changed since).',
            $dryRun ? 'Would restore' : 'Restored',
            $restored,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * Yield every migratable row across all storage columns, each with its resolved legacy and new path. Rows that are
     * already migrated, unrecognised, or without a scope are skipped here so the phases never see them.
     *
     * Each column is streamed with `toIterable()`, so a consumer may flush and clear the entity manager between rows to
     * keep memory flat over a large photo set; clearing while this generator is suspended is safe (the database cursor
     * is independent of the identity map).
     *
     * @return Generator<int, MigrationRow>
     */
    private function migratableRows(): Generator
    {
        foreach ($this->targets() as $target) {
            $namespace = $target['namespace'];
            $field = $target['field'];
            $key = $target['key'];

            foreach ($this->entitiesFor($key) as $entity) {
                assert(is_object($entity));

                $legacy = $this->readPathField(
                    $entity,
                    $field,
                );
                if (
                    null === $legacy
                    || '' === $legacy
                ) {
                    continue;
                }

                $scope = $this->resolveScope($entity);
                if (
                    $namespace->requiresScope()
                    && null === $scope
                ) {
                    continue;
                }

                $new = $this->mapLegacyPath(
                    $namespace,
                    $legacy,
                    $scope,
                );
                if (null === $new) {
                    continue;
                }

                yield [
                    'key' => $key,
                    'entity' => $entity,
                    'field' => $field,
                    'legacy' => $legacy,
                    'new' => $new,
                ];
            }
        }
    }

    /**
     * The six storage columns to migrate, each with its path field and target namespace. The matching entities are
     * streamed separately by {@see entitiesFor()} (keyed off the stable key), so the generic Doctrine query type never
     * has to be pinned in a shared type alias.
     *
     * @return list<StorageTarget>
     */
    private function targets(): array
    {
        return [
            [
                'key' => self::KEY_PHOTO,
                'field' => 'path',
                'namespace' => StorageNamespace::PhotoOriginal,
            ],
            [
                'key' => self::KEY_COURSE_DOCUMENT,
                'field' => 'path',
                'namespace' => StorageNamespace::EducationDocument,
            ],
            [
                'key' => self::KEY_ALBUM_COVER,
                'field' => 'coverPath',
                'namespace' => StorageNamespace::PhotoCover,
            ],
            [
                'key' => self::KEY_COMPANY_LOGO,
                'field' => 'squareLogo',
                'namespace' => StorageNamespace::CompanyImage,
            ],
            [
                'key' => self::KEY_COMPANY_BANNER,
                'field' => 'image',
                'namespace' => StorageNamespace::CompanyImage,
            ],
            [
                'key' => self::KEY_COMPANY_BANNER_PENDING,
                'field' => 'pendingImage',
                'namespace' => StorageNamespace::CompanyImage,
            ],
            [
                'key' => self::KEY_COMPANY_BANNER_LOGO,
                'field' => 'bannerLogo',
                'namespace' => StorageNamespace::CompanyImage,
            ],
            [
                'key' => self::KEY_ORGAN_COVER,
                'field' => 'coverPath',
                'namespace' => StorageNamespace::OrganImage,
            ],
            [
                'key' => self::KEY_ORGAN_THUMBNAIL,
                'field' => 'thumbnailPath',
                'namespace' => StorageNamespace::OrganImage,
            ],
            // A body's images are kept twice over: the file as it was uploaded, and the cut of it that is shown. The
            // migration that split them filled both columns with the same legacy path, so both have to be rewritten,
            // or the edit screen ends up framing a file that is no longer where it says it is.
            [
                'key' => self::KEY_ORGAN_BANNER_SOURCE,
                'field' => 'bannerSource',
                'namespace' => StorageNamespace::OrganImage,
            ],
            [
                'key' => self::KEY_ORGAN_LOGO_SOURCE,
                'field' => 'logoSource',
                'namespace' => StorageNamespace::OrganImage,
            ],
        ];
    }

    /**
     * Stream the entities of the column identified by $key, one at a time (`toIterable()`), so the caller can flush and
     * clear the entity manager between rows. The value type is left fully open on purpose: the two static analysers
     * infer different generic types for a Doctrine query, so the concrete row type is asserted at the call site.
     *
     * @return iterable<mixed, mixed>
     */
    private function entitiesFor(string $key): iterable
    {
        $repository = match ($key) {
            self::KEY_PHOTO => $this->photoRepository,
            self::KEY_ALBUM_COVER => $this->albumRepository,
            self::KEY_COMPANY_LOGO,
            self::KEY_COMPANY_BANNER_LOGO => $this->companyRevisionRepository,
            self::KEY_COMPANY_BANNER,
            self::KEY_COMPANY_BANNER_PENDING => $this->companyBannerPackageRepository,
            self::KEY_ORGAN_COVER,
            self::KEY_ORGAN_THUMBNAIL,
            self::KEY_ORGAN_BANNER_SOURCE,
            self::KEY_ORGAN_LOGO_SOURCE => $this->organInformationRevisionRepository,
            self::KEY_COURSE_DOCUMENT => $this->courseDocumentRepository,
            default => throw new RuntimeException(sprintf('Unknown storage target "%s".', $key)),
        };

        return $repository->createQueryBuilder('e')->getQuery()->toIterable();
    }

    /**
     * Read the current stored value of the given path field off an entity.
     */
    private function readPathField(
        object $entity,
        string $field,
    ): ?string {
        switch (true) {
            case $entity instanceof Photo:
                return $entity->getPath();

            case $entity instanceof Album:
                return $entity->getCoverPath();

            case $entity instanceof CompanyRevision:
                return 'bannerLogo' === $field
                    ? $entity->getBannerLogo()
                    : $entity->getSquareLogo();

            case $entity instanceof CompanyBannerPackage:
                return 'pendingImage' === $field
                    ? $entity->getPendingImage()
                    : $entity->getImage();

            case $entity instanceof CourseDocument:
                return $entity->getPath();

            case $entity instanceof OrganInformationRevision:
                return match ($field) {
                    'thumbnailPath' => $entity->getLogoPath(),
                    'bannerSource' => $entity->getBannerSource(),
                    'logoSource' => $entity->getLogoSource(),
                    default => $entity->getBannerPath(),
                };

            default:
                throw new RuntimeException(sprintf('Cannot read a storage path from "%s".', $entity::class));
        }
    }

    /**
     * Write a new value into the given path field of an entity.
     */
    private function writePathField(
        object $entity,
        string $field,
        string $value,
    ): void {
        switch (true) {
            case $entity instanceof Photo:
                $entity->setPath($value);

                return;

            case $entity instanceof Album:
                $entity->setCoverPath($value);

                return;

            case $entity instanceof CompanyRevision:
                if ('bannerLogo' === $field) {
                    $entity->setBannerLogo($value);
                } else {
                    $entity->setSquareLogo($value);
                }

                return;

            case $entity instanceof CompanyBannerPackage:
                if ('pendingImage' === $field) {
                    $entity->setPendingImage($value);

                    return;
                }

                $entity->setImage($value);

                return;

            case $entity instanceof CourseDocument:
                $entity->setPath($value);

                return;

            case $entity instanceof OrganInformationRevision:
                match ($field) {
                    'thumbnailPath' => $entity->setLogoPath($value),
                    'bannerSource' => $entity->setBannerSource($value),
                    'logoSource' => $entity->setLogoSource($value),
                    default => $entity->setBannerPath($value),
                };

                return;

            default:
                throw new RuntimeException(sprintf('Cannot write a storage path to "%s".', $entity::class));
        }
    }

    /**
     * The scope (as a string id) for a scoped entity: the album for a photo or album cover, the company for a company
     * asset, or null for the non-scoped namespaces.
     */
    private function resolveScope(object $entity): ?string
    {
        if ($entity instanceof Photo) {
            $id = $entity->getAlbum()->getId();
        } elseif ($entity instanceof Album) {
            $id = $entity->getId();
        } elseif ($entity instanceof CompanyRevision) {
            $id = $entity->getCompany()->getId();
        } elseif ($entity instanceof CompanyBannerPackage) {
            $id = $entity->getCompany()->getId();
        } elseif ($entity instanceof CourseDocument) {
            // Course documents are filed per course, and a course is identified by its code rather than a number.
            return $entity->getCourse()->getCode();
        } else {
            return null;
        }

        return null === $id
            ? null
            : strval($id);
    }

    /**
     * The path field a log key refers to, or null when the key is unknown (e.g. a log from a newer version).
     */
    private function fieldForKey(string $key): ?string
    {
        return match ($key) {
            self::KEY_PHOTO => 'path',
            self::KEY_ALBUM_COVER => 'coverPath',
            self::KEY_COMPANY_LOGO => 'squareLogo',
            self::KEY_COMPANY_BANNER => 'image',
            self::KEY_ORGAN_COVER => 'coverPath',
            self::KEY_ORGAN_THUMBNAIL => 'thumbnailPath',
            self::KEY_COURSE_DOCUMENT => 'path',
            default => null,
        };
    }

    /**
     * Look a logged row back up by its stable key and id, through the matching (typed) repository.
     */
    private function findByKey(
        string $key,
        int $id,
    ): ?object {
        return match ($key) {
            self::KEY_PHOTO => $this->photoRepository->find($id),
            self::KEY_ALBUM_COVER => $this->albumRepository->find($id),
            self::KEY_COMPANY_LOGO => $this->companyRevisionRepository->find($id),
            self::KEY_COMPANY_BANNER => $this->companyBannerPackageRepository->find($id),
            self::KEY_ORGAN_COVER, self::KEY_ORGAN_THUMBNAIL => $this->organInformationRevisionRepository->find($id),
            default => null,
        };
    }

    /**
     * The integer identifier of a managed entity, read through the ORM metadata (so no shared id interface is needed).
     */
    private function entityId(object $entity): int
    {
        $identifiers = $this->entityManager
            ->getClassMetadata($entity::class)
            ->getIdentifierValues($entity);

        $id = $identifiers['id'] ?? null;
        if (!is_int($id)) {
            throw new RuntimeException(sprintf('Expected an integer id on "%s".', $entity::class));
        }

        return $id;
    }

    /**
     * Classify what linking $source to $destination would do, without performing it: the source is missing, the
     * destination already exists (skip), or a hardlink would be created. The dry run reports this; {@see linkFile()}
     * performs the link for the {@see LINK_LINKED} case.
     */
    private function classifyLink(
        string $source,
        string $destination,
    ): string {
        if (!is_file($source)) {
            return self::LINK_MISSING_SOURCE;
        }

        if (file_exists($destination)) {
            return self::LINK_SKIPPED;
        }

        return self::LINK_LINKED;
    }

    /**
     * Hardlink one file, creating the destination directory as needed. Returns which of the three outcomes occurred.
     */
    private function linkFile(
        string $source,
        string $destination,
    ): string {
        $status = $this->classifyLink(
            $source,
            $destination,
        );
        if (self::LINK_LINKED !== $status) {
            return $status;
        }

        $directory = dirname($destination);
        if (
            !is_dir($directory)
            && !mkdir(
                $directory,
                0o775,
                true,
            )
            && !is_dir($directory)
        ) {
            throw new RuntimeException(sprintf('Could not create the destination directory "%s".', $directory));
        }

        // A hardlink costs no disk and is instant, which is why it is tried first. It only works within one
        // filesystem, though, and the two layouts need not share one: a deployment that keeps `public/` on the image
        // and `data/` on a volume has them on different devices, where link() fails with EXDEV. Copying is the same
        // migration, slower and heavier, so it is the fallback rather than the rule.
        if (
            @link(
                $source,
                $destination,
            )
        ) {
            return self::LINK_LINKED;
        }

        if (
            copy(
                $source,
                $destination,
            )
        ) {
            return self::LINK_COPIED;
        }

        return self::LINK_FAILED;
    }

    /**
     * Write the rollback log for a `--paths` run and return its absolute path.
     *
     * Build the path for a new rollback log (JSON Lines, one entry per line so it can be appended to durably) and
     * ensure its directory exists. The file itself is created on the first {@see appendLog()}.
     */
    private function newLogFile(): string
    {
        $directory = $this->logDirectory();
        if (
            !is_dir($directory)
            && !mkdir(
                $directory,
                0o775,
                true,
            )
            && !is_dir($directory)
        ) {
            throw new RuntimeException(sprintf('Could not create the log directory "%s".', $directory));
        }

        return sprintf(
            '%s/paths-%s.jsonl',
            $directory,
            date('Ymd-His'),
        );
    }

    /**
     * Append a batch of rollback entries to the log file, one JSON object per line.
     *
     * @param list<LogEntry> $entries
     */
    private function appendLog(
        string $file,
        array $entries,
    ): void {
        if ([] === $entries) {
            return;
        }

        $lines = '';
        foreach ($entries as $entry) {
            $lines .= json_encode(
                $entry,
                JSON_THROW_ON_ERROR,
            ) . "\n";
        }

        if (
            false === file_put_contents(
                $file,
                $lines,
                FILE_APPEND | LOCK_EX,
            )
        ) {
            throw new RuntimeException(sprintf('Could not write the rollback log to "%s".', $file));
        }
    }

    /**
     * Read and validate a rollback log, returning only well-formed entries with a known key. Malformed lines are
     * skipped rather than aborting the restore.
     *
     * @return list<LogEntry>
     */
    private function readLog(string $file): array
    {
        $contents = file_get_contents($file);
        if (false === $contents) {
            throw new RuntimeException(sprintf('Could not read the rollback log "%s".', $file));
        }

        $entries = [];
        foreach (
            explode(
                "\n",
                $contents,
            ) as $line
        ) {
            if ('' === trim($line)) {
                continue;
            }

            try {
                $decoded = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $e) {
                throw new RuntimeException(
                    sprintf(
                        'The rollback log "%s" has an invalid line: %s',
                        $file,
                        $e->getMessage(),
                    ),
                    previous: $e,
                );
            }

            if (
                !is_array($decoded)
                || !isset($decoded['key'], $decoded['id'], $decoded['old'], $decoded['new'])
                || !is_string($decoded['key'])
            ) {
                continue;
            }

            $entries[] = [
                'key' => $decoded['key'],
                'id' => intval($decoded['id']),
                'old' => strval($decoded['old']),
                'new' => strval($decoded['new']),
            ];
        }

        return $entries;
    }

    /**
     * Resolve which rollback log to restore from: the explicit --log value, or the most recent log otherwise.
     */
    private function resolveLogFile(?string $logOption): ?string
    {
        if (null !== $logOption) {
            $path = str_starts_with(
                $logOption,
                '/',
            )
                ? $logOption
                : $this->projectDir . '/' . $logOption;

            return is_file($path)
                ? $path
                : null;
        }

        $matches = glob($this->logDirectory() . '/paths-*.jsonl');
        if (
            false === $matches
            || [] === $matches
        ) {
            return null;
        }

        // The filenames carry a sortable timestamp, so the last entry is the most recent run.
        sort($matches);

        return $matches[count($matches) - 1];
    }

    /**
     * Print a small sample of the legacy-to-new mappings, so a dry run makes the transformation concrete.
     *
     * @param list<array{0: string, 1: string}> $sample
     */
    private function reportSample(
        SymfonyStyle $ui,
        array $sample,
    ): void {
        if ([] === $sample) {
            return;
        }

        $ui->table(
            [
                'Legacy path',
                'New path',
            ],
            $sample,
        );
    }

    /**
     * Ask for confirmation before a destructive run; a dry run and non-interactive runs (cron/CI) proceed silently.
     */
    private function confirmDestructive(
        SymfonyStyle $ui,
        InputInterface $input,
        bool $dryRun,
        string $action,
    ): bool {
        if ($dryRun) {
            return true;
        }

        return $ui->confirm(
            sprintf(
                'This will %s. Continue?',
                $action,
            ),
            !$input->isInteractive(),
        );
    }

    /**
     * Parse and validate the --batch-size option; null signals an invalid value (already reported to the user).
     */
    private function batchSize(
        SymfonyStyle $ui,
        InputInterface $input,
    ): ?int {
        $value = intval($input->getOption('batch-size'));

        if ($value < 1) {
            $ui->error('The --batch-size must be a positive integer.');

            return null;
        }

        return $value;
    }

    private function stringOption(
        InputInterface $input,
        string $name,
    ): ?string {
        $value = $input->getOption($name);

        return is_string($value)
            ? $value
            : null;
    }

    private function legacyRoot(): string
    {
        return $this->legacyRoot;
    }

    private function newRoot(): string
    {
        return $this->projectDir . '/data';
    }

    private function logDirectory(): string
    {
        return $this->projectDir . '/var/storage-migration';
    }
}
