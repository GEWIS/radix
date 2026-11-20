<?php

declare(strict_types=1);

namespace App\Command\Application;

use App\Entity\Decision\Member;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\NamedObject;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function bin2hex;
use function count;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * Copies the website's database (MariaDB, the `web` connection) into the application database (PostgreSQL, the
 * `application` connection), converting each value the engines spell differently. Run in a maintenance window,
 * and once more right before the switch; after the switch, run `app:decision:generate` to replay whatever the
 * projection listeners wrote between the runs.
 */
#[AsCommand(
    name: 'app:web:copy-to-application',
    description: 'Copy the web database into the application database, converting the schema as it goes.',
)]
final class CopyWebToApplicationCommand extends Command
{
    private const int BATCH_SIZE = 500;

    /**
     * The largest batch payload; a table whose rows are large flushes before BATCH_SIZE rows accumulate.
     */
    private const int BATCH_BYTES = 8 * 1024 * 1024;

    /**
     * Introspected identifiers keep the quotes the migration needed to preserve the case (`"Authorization"`);
     * the schema looks its assets up without them.
     *
     * @param NamedObject<OptionallyQualifiedName>|NamedObject<UnqualifiedName> $asset
     */
    private static function plainName(
        NamedObject $asset,
    ): string {
        return trim(
            $asset->getObjectName()->toString(),
            '"',
        );
    }

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        private readonly Connection $web,
        #[Autowire(service: 'doctrine.dbal.application_connection')]
        private readonly Connection $application,
        #[Autowire(service: 'doctrine.orm.application_entity_manager')]
        private readonly EntityManagerInterface $webManager,
    ) {
        parent::__construct();
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

        if (!$this->web->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            throw new RuntimeException('The web connection is not MariaDB, so there is nothing to copy.');
        }

        if (!$this->application->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new RuntimeException('The application connection is not PostgreSQL, so there is nowhere to copy to.');
        }

        $ui->title('Copying the web database into the application database');

        if (
            !$ui->confirm(
                sprintf(
                    'The application database "%s" will be emptied and refilled. Do you want to continue?',
                    $this->application->getDatabase() ?? '',
                ),
                !$input->isInteractive(),
            )
        ) {
            return Command::SUCCESS;
        }

        $sourceSchema = $this->web->createSchemaManager()->introspectSchema();
        $targetSchema = $this->application->createSchemaManager()->introspectSchema();

        $sourceTables = $sourceSchema->getTables();
        usort(
            $sourceTables,
            static fn (Table $left, Table $right): int => $left->getObjectName()->toString()
                <=> $right->getObjectName()->toString(),
        );

        // Each connection keeps its own migration bookkeeping; the version table must not cross the copy.
        $sourceTables = array_filter(
            $sourceTables,
            static fn (Table $table): bool => 'doctrine_migration_versions' !== self::plainName($table),
        );

        // The target mirrors the source after the application migrations ran; a table it lacks means the wrong
        // schema is in front of us.
        $targetTables = [];
        foreach ($sourceTables as $table) {
            $name = self::plainName($table);
            if (!$targetSchema->hasTable($name)) {
                throw new RuntimeException(sprintf(
                    'The application database lacks table "%s"; run the application migrations first.',
                    $name,
                ));
            }

            $targetTables[$name] = $targetSchema->getTable($name);
        }

        // One transaction with the foreign keys deferred (the baseline declares them DEFERRABLE): the revision
        // tables reference one another in cycles no table order can satisfy, and a deferred check only runs at
        // commit. An interrupted copy rolls back whole.
        $this->application->beginTransaction();
        try {
            $this->application->executeStatement('SET CONSTRAINTS ALL DEFERRED');

            // Naming every table together satisfies the foreign keys between them.
            $this->application->executeStatement(sprintf(
                'TRUNCATE %s RESTART IDENTITY',
                implode(
                    ', ',
                    array_map(
                        fn (Table $table): string => $table->getObjectName()->toSQL(
                            $this->application->getDatabasePlatform(),
                        ),
                        array_values($targetTables),
                    ),
                ),
            ));

            foreach ($sourceTables as $table) {
                $this->copyTable(
                    $table,
                    $targetTables[self::plainName($table)],
                    $ui,
                );
            }

            $this->resetIdentitySequences(
                $targetTables,
                $ui,
            );

            $this->application->commit();
        } catch (Throwable $exception) {
            $this->application->rollBack();

            throw $exception;
        }

        // Raw SQL writes bypass the second-level cache, so a page reading a member by id keeps serving the pre-copy
        // row after the switch. Member is the only cached entity (region `member_region`).
        $cache = $this->webManager->getCache();
        if (null !== $cache) {
            $cache->evictEntityRegion(Member::class);
        }

        $ui->success('The application database now holds a copy of the web database');

        return Command::SUCCESS;
    }

    private function copyTable(
        Table $source,
        Table $target,
        SymfonyStyle $ui,
    ): void {
        // Matched case-insensitively: PostgreSQL folded the unquoted names the migration created, so the target
        // reports `beginsat` where the source has `beginsAt`.
        $targetColumns = [];
        foreach ($target->getColumns() as $column) {
            $targetColumns[strtolower(self::plainName($column))] = $column;
        }

        $columnNames = [];
        $columnTypes = [];
        foreach ($source->getColumns() as $column) {
            $name = self::plainName($column);
            $targetColumn = $targetColumns[strtolower($name)] ?? null;
            if (null === $targetColumn) {
                throw new RuntimeException(sprintf(
                    'The application table "%s" lacks column "%s"; run the application migrations.',
                    self::plainName($source),
                    $name,
                ));
            }

            $columnNames[] = $name;
            $columnTypes[$name] = $targetColumn->getTypeName();
        }

        $platform = $this->application->getDatabasePlatform();
        $targetName = $target->getObjectName()->toSQL($platform);

        $batch = [];
        $batchBytes = 0;
        $inserted = 0;
        foreach (
            $this->sourceRows(
                $source,
                $columnNames,
            ) as $row
        ) {
            $batch[] = $row;
            foreach ($row as $value) {
                $batchBytes += is_string($value)
                    ? strlen($value)
                    : 8;
            }

            if (
                count($batch) < self::BATCH_SIZE
                && $batchBytes < self::BATCH_BYTES
            ) {
                continue;
            }

            $inserted += $this->insertBatch(
                $targetName,
                $columnTypes,
                $batch,
            );
            $batch = [];
            $batchBytes = 0;
        }

        if ([] !== $batch) {
            $inserted += $this->insertBatch(
                $targetName,
                $columnTypes,
                $batch,
            );
        }

        $ui->text(sprintf(
            '%s: %d rows',
            self::plainName($source),
            $inserted,
        ));
    }

    /**
     * Streams row by row on the unbuffered web connection, so neither a table's size nor its row size can
     * exhaust the client's memory. A keyset would have to agree with the engine's ordering for every key shape
     * (an ENUM orders by definition index but compares to a bound string by collation), and streaming needs
     * none of that.
     *
     * @param list<string> $columns
     *
     * @return iterable<array<string, mixed>>
     */
    private function sourceRows(
        Table $source,
        array $columns,
    ): iterable {
        yield from $this->web->executeQuery(
            sprintf(
                'SELECT %s FROM %s',
                implode(
                    ', ',
                    $columns,
                ),
                $source->getObjectName()->toSQL($this->web->getDatabasePlatform()),
            ),
        )->iterateAssociative();
    }

    /**
     * @param array<string, string>      $columnTypes
     * @param list<array<string, mixed>> $rows
     */
    private function insertBatch(
        string $targetName,
        array $columnTypes,
        array $rows,
    ): int {
        $placeholders = [];
        $params = [];
        $types = [];
        $columns = array_keys($columnTypes);

        foreach ($rows as $rowNumber => $row) {
            $tuple = [];
            foreach ($columns as $column) {
                $placeholder = sprintf(
                    'r%d_%s',
                    $rowNumber,
                    $column,
                );
                $tuple[] = ':' . $placeholder;
                $params[$placeholder] = $this->convertValue(
                    $row[$column] ?? null,
                    $columnTypes[$column],
                );
                $types[$placeholder] = $this->bindingType($columnTypes[$column]);
            }

            $placeholders[] = '(' . implode(
                ', ',
                $tuple,
            ) . ')';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $targetName,
            implode(
                ', ',
                $columns,
            ),
            implode(
                ', ',
                $placeholders,
            ),
        );

        return (int) $this->application->executeStatement(
            $sql,
            $params,
            $types,
        );
    }

    /**
     * A value from MariaDB is not always spelled the way the target reads it: a UUID came out of BINARY(16)
     * and has to go in as its canonical text, a zero datetime as the sentinel for an unset date; the rest only
     * needs its type to be bound.
     */
    private function convertValue(
        mixed $value,
        string $typeName,
    ): mixed {
        if (null === $value) {
            return null;
        }

        // MariaDB stores an unset datetime as `0000-00-00 00:00:00`, which PostgreSQL refuses; the copy writes the
        // ledger's sentinel for an unset date (`0001-01-01 00:00:00`, a deleted member) because the column may be
        // NOT NULL. The type name arrives in its immutable spelling (`datetime_immutable`), hence both forms.
        if (
            is_string($value)
            && str_starts_with(
                $value,
                '0000-00-00',
            )
            && in_array(
                $typeName,
                [
                    'date',
                    'date_immutable',
                    'datetime',
                    'datetime_immutable',
                    'datetimetz',
                    'datetimetz_immutable',
                ],
                true,
            )
        ) {
            return in_array(
                $typeName,
                [
                    'date',
                    'date_immutable',
                ],
                true,
            )
                ? '0001-01-01'
                : '0001-01-01 00:00:00';
        }

        // PostgreSQL reports the column as `guid`, the name its platform maps the native uuid type to.
        if ('guid' === $typeName) {
            if (
                is_string($value)
                && 16 === strlen($value)
            ) {
                $hex = bin2hex($value);
                $parts = [
                    substr(
                        $hex,
                        0,
                        8,
                    ),
                    substr(
                        $hex,
                        8,
                        4,
                    ),
                    substr(
                        $hex,
                        12,
                        4,
                    ),
                    substr(
                        $hex,
                        16,
                        4,
                    ),
                    substr(
                        $hex,
                        20,
                        12,
                    ),
                ];

                return implode(
                    '-',
                    $parts,
                );
            }

            return is_string($value)
                ? strtolower($value)
                : $value;
        }

        return $value;
    }

    /**
     * The binding type, not the column type: text, JSON and timestamps cross the engines as their strings, and
     * only booleans and integers need the driver to translate them.
     */
    private function bindingType(string $typeName): string
    {
        return match ($typeName) {
            'boolean' => 'boolean',
            'smallint', 'integer', 'bigint' => 'integer',
            default => 'string',
        };
    }

    /**
     * The copies carry the source's ids, which leaves each identity sequence at 1. Advancing it past the
     * largest id copied keeps the next idless insert from colliding with the copied rows.
     *
     * @param array<string, Table> $tables
     */
    private function resetIdentitySequences(
        array $tables,
        SymfonyStyle $ui,
    ): void {
        $platform = $this->application->getDatabasePlatform();
        $reset = 0;

        foreach ($tables as $table) {
            foreach ($table->getColumns() as $column) {
                if (!$column->getAutoincrement()) {
                    continue;
                }

                // pg_get_serial_sequence parses its first argument as a regclass, so a table that kept its case
                // (`"Authorization"`) must be quoted; the column name is matched verbatim. The third setval argument
                // says the value was already used: with rows the next id is max + 1, without rows 1.
                $tableName = $table->getObjectName()->toSQL($platform);
                $columnName = $column->getObjectName()->toSQL($platform);
                $this->application->executeStatement(sprintf(
                    'SELECT setval(pg_get_serial_sequence(%s, %s), COALESCE(MAX(%s), 1), COUNT(*) > 0) FROM %s',
                    $this->application->quote($tableName),
                    $this->application->quote(self::plainName($column)),
                    $columnName,
                    $tableName,
                ));
                $reset++;
            }
        }

        if ($reset <= 0) {
            return;
        }

        $ui->text(sprintf(
            '%d identity %s advanced past the copied rows.',
            $reset,
            1 === $reset ? 'sequence was' : 'sequences were',
        ));
    }
}
