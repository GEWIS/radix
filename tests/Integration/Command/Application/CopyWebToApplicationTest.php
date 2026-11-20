<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command\Application;

use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Connection;
use Override;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function implode;
use function sprintf;
use function strtolower;
use function trim;

/**
 * The web-to-application copy carries the database across engines; the test pins what that must preserve: row
 * counts, ids that do not collide with the next idless insert, UUIDs read back as text, zero datetimes as the
 * ledger's sentinel, and a composite-key table arriving complete.
 */
final class CopyWebToApplicationTest extends DatabaseTestCase
{
    private Connection $application;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $application = self::getContainer()->get('doctrine')->getConnection('application');
        self::assertInstanceOf(
            Connection::class,
            $application,
        );
        $this->application = $application;
    }

    public function testEveryTableArrivesWithTheSameRows(): void
    {
        $this->copy();

        $web = $this->entityManager->getConnection();
        // The introspection keeps the quotes `"User"` and `"Authorization"` need; strip them so both sides meet on
        // the plain name.
        $targetNames = [];
        foreach ($this->application->createSchemaManager()->introspectTableNames() as $targetName) {
            $plain = trim(
                $targetName->toString(),
                '"',
            );
            $targetNames[strtolower($plain)] = $plain;
        }

        foreach ($web->createSchemaManager()->introspectTableNames() as $tableName) {
            $plain = trim(
                $tableName->toString(),
                '"',
            );
            if ('doctrine_migration_versions' === $plain) {
                // Each connection keeps its own migration bookkeeping; the copy must not clobber it.
                continue;
            }

            self::assertArrayHasKey(
                strtolower($plain),
                $targetNames,
                sprintf(
                    'Table "%s" was not copied at all.',
                    $plain,
                ),
            );

            self::assertSame(
                (int) $web->fetchOne($this->countSql($web, $plain)),
                (int) $this->application->fetchOne(
                    $this->countSql(
                        $this->application,
                        $targetNames[strtolower($plain)],
                    ),
                ),
                sprintf(
                    'Table "%s" was copied incompletely.',
                    $plain,
                ),
            );
        }
    }

    public function testCopiedRowsLeaveTheNextInsertAndTheUUIDsWorking(): void
    {
        // A few rows stand in for what the copy carries; the transport table is injected into the test schema,
        // and the copy must take them over.
        $web = $this->entityManager->getConnection();
        for ($id = 0; $id < 3; $id++) {
            $web->executeStatement(
                'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) '
                . "VALUES ('a body " . $id . "', '[]', 'failed', NOW(), NOW())",
            );
        }

        $maximumId = (int) $web->fetchOne('SELECT COALESCE(MAX(id), 0) FROM messenger_messages');

        $this->copy();

        // The copy carries explicit ids, so the identity sequence stays at 1; the command advances it past the
        // last copied id. This idless insert would collide with a copied id otherwise.
        $this->application->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) '
            . "VALUES ('a body', '[]', 'failed', NOW(), NOW())",
        );
        self::assertSame(
            $maximumId + 1,
            (int) $this->application->fetchOne('SELECT MAX(id) FROM messenger_messages'),
            'The identity advance after the copy was skipped or off.',
        );

        // A UUID stored as BINARY(16) on MariaDB has to come out of the copy as the text PostgreSQL stores; the
        // sign-up lists' lineage id is the uuid column the fixtures always fill.
        $lineageId = $this->application->fetchOne(
            'SELECT lineageid FROM signuplist WHERE lineageid IS NOT NULL LIMIT 1',
        );
        self::assertIsString($lineageId);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $lineageId,
            'The UUID did not come out of BINARY(16) as canonical text.',
        );
    }

    /**
     * A composite key used to be why a table was fetched whole rather than paged; it must still arrive
     * complete. MeetingLocalDetails is the smallest table with a composite key and no foreign keys to satisfy.
     */
    public function testCompositeKeyTableCopiesEveryRow(): void
    {
        $web = $this->webConnection();
        $values = [];
        foreach (['BV', 'ALV'] as $meetingType) {
            for ($number = 1; $number <= 300; $number++) {
                $values[] = sprintf(
                    "('%s', %d, NOW(), NOW())",
                    $meetingType,
                    $number,
                );
            }
        }

        $web->executeStatement(
            'INSERT INTO MeetingLocalDetails (meeting_type, meeting_number, createdAt, updatedAt) VALUES '
            . implode(
                ', ',
                $values,
            ),
        );

        $this->copy();

        self::assertSame(
            600,
            (int) $this->application->fetchOne(
                'SELECT COUNT(*) FROM MeetingLocalDetails',
            ),
        );
    }

    /**
     * MariaDB keeps `0000-00-00 00:00:00` for a datetime that was never set, a value PostgreSQL refuses to parse.
     * The copy writes the ledger's sentinel for an unset date instead; the column is NOT NULL here, so converting
     * to NULL would not do.
     */
    public function testZeroDateTimesBecomeTheUnsetDateSentinel(): void
    {
        $web = $this->webConnection();
        $web->executeStatement(
            'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) '
            . "VALUES ('zero', '[]', 'failed', '0000-00-00 00:00:00', '0000-00-00 00:00:00')",
        );

        $this->copy();

        self::assertSame(
            '0001-01-01 00:00:00',
            (string) $this->application->fetchOne(
                "SELECT created_at FROM messenger_messages WHERE body = 'zero'",
            ),
        );
    }

    /**
     * The smallest row graph that exercises the BINARY(16) conversion: a sign-up list needs a localised text for
     * its name and an activity revision to belong to, both created here as well so the copied rows satisfy the
     * foreign keys.
     */
    private function insertSourceGraph(): void
    {
        $web = $this->webConnection();
        $web->executeStatement(
            'INSERT INTO ActivityLocalisedText (id, valueEN, valueNL) VALUES '
            . "(1, 'name', 'naam'), (2, 'place', 'plaats'), (3, 'price', 'prijs'), (4, 'info', 'info')",
        );
        $web->executeStatement('INSERT INTO Activity (id) VALUES (1)');
        $web->executeStatement(
            'INSERT INTO ActivityRevision (id, activity_id, name_id, location_id, costs_id, description_id,'
            . ' revisionNumber, status, createdAt, updatedAt, beginTime, endTime, category, requireGEFLITST,'
            . ' requireZettle, version) '
            . "VALUES (1, 1, 1, 2, 3, 4, 1, 'draft', NOW(), NOW(), NOW(), NOW(), 'standards', 0, 0, 1)",
        );
        $web->executeStatement(
            'INSERT INTO SignupList (id, lineageId, name_id, activity_revision_id, onlyGEWIS,'
            . ' displaySubscribedNumber, limitedCapacity, presenceTaken, promoted, allocationMethod,'
            . ' externalForceOrdering, externalPaymentByExternal) '
            . "VALUES (1, UNHEX('a0b1c2d3e4f5061728394a5b6c7d8e9f'), 1, 1, 0, 0, 0, 0, 0, 'full', 0, 0)",
        );
    }

    private function webConnection(): Connection
    {
        $web = self::getContainer()->get('doctrine')->getConnection('web');
        self::assertInstanceOf(
            Connection::class,
            $web,
        );

        return $web;
    }

    private function copy(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:web:copy-to-application'));
        // Answer the "empty and refill" confirmation; --no-interaction would default it to yes, but answering is
        // explicit about what the test expects.
        $tester->setInputs(['yes']);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
    }

    private function countSql(
        Connection $connection,
        string $tableName,
    ): string {
        return sprintf(
            'SELECT COUNT(*) FROM %s',
            $connection->getDatabasePlatform()->quoteSingleIdentifier($tableName),
        );
    }
}
