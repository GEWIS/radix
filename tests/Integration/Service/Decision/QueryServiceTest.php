<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Decision;

use App\Entity\Database\SavedQuery;
use App\Service\Decision\QueryService;
use App\Tests\Integration\DatabaseTestCase;
use App\Tests\Support\LedgerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_map;
use function count;
use function sort;
use function strtolower;

/**
 * The query console: the queries an administrator stored, and running the DQL they type. The console is a read
 * surface over the projection, and what it may reach is an allowlist rather than whatever the mapping contains.
 */
#[CoversClass(QueryService::class)]
final class QueryServiceTest extends DatabaseTestCase
{
    private EntityManagerInterface $ledger;
    private QueryService $service;
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
        $this->service = self::getContainer()->get(QueryService::class);
    }

    public function testSavedQueriesComeBackOrderedByCategoryThenName(): void
    {
        $queries = $this->service->getSavedQueries();

        self::assertGreaterThanOrEqual(
            2,
            count($queries),
        );

        $this->service->save(
            'Aardappelen',
            'AAA',
            'SELECT m FROM db:Member m',
        );

        $queries = $this->service->getSavedQueries();
        $categories = array_map(
            static fn (SavedQuery $query): string => strtolower($query->category),
            $queries,
        );
        $sorted = $categories;
        sort($sorted);

        self::assertSame(
            $sorted,
            $categories,
        );
        self::assertSame(
            'AAA',
            $queries[0]->category,
        );
        self::assertSame(
            'Aardappelen',
            $queries[0]->name,
        );
    }

    public function testSaveCreatesAQueryAndUpdatesItByNameOnTheSecondCall(): void
    {
        $created = $this->service->save(
            'Unit test query',
            'Test',
            'SELECT m FROM db:Member m',
        );

        self::assertNotNull($created->id);
        $id = $created->id;

        $updated = $this->service->save(
            'unit test query',
            'Test',
            'SELECT m FROM db:Member m WHERE m.hidden = false',
        );

        self::assertSame(
            $id,
            $updated->id,
        );
        self::assertSame(
            'unit test query',
            $updated->name,
        );
        self::assertSame(
            'SELECT m FROM db:Member m WHERE m.hidden = false',
            $updated->query,
        );
        self::assertSame(
            1,
            $this->ledgerCountByName('unit test query'),
        );
    }

    public function testDeleteRemovesAStoredQuery(): void
    {
        $query = $this->service->save(
            'Verwijderbare query',
            'Test',
            'SELECT m FROM db:Member m',
        );
        self::assertNotNull($query->id);
        $id = $query->id;

        $this->service->delete($query);

        self::assertNull(
            $this->service->getSavedQuery($id),
        );
    }

    public function testGetSavedQueryReturnsTheStoredOneAndNullOtherwise(): void
    {
        $seeded = $this->service->getSavedQueries()[0];
        self::assertNotNull($seeded->id);

        self::assertSame(
            $seeded->id,
            $this->service->getSavedQuery($seeded->id)?->id,
        );
        self::assertNull(
            $this->service->getSavedQuery(999_999),
        );
    }

    public function testExecuteAMeetingNumberQuery(): void
    {
        $meeting = $this->build->meeting(
            date: '2026-08-20',
        );

        $rows = $this->service->execute(
            'SELECT m.number, m.date FROM db:Meeting m WHERE m.number = ' . $meeting->getNumber(),
        );

        self::assertCount(
            1,
            $rows,
        );
        self::assertSame(
            $meeting->getNumber(),
            $rows[0]['number'],
        );
        self::assertSame(
            '2026-08-20',
            $rows[0]['date'],
        );
    }

    public function testExecuteAFreeTextQuery(): void
    {
        $this->build->decideFreely(
            $this->build->meeting(),
            'Deze query gaat over taart.',
        );

        $rows = $this->service->execute(
            "SELECT d.contentNL FROM db:Decision d WHERE d.contentNL LIKE '%taart%'",
        );

        self::assertNotEmpty($rows);

        foreach ($rows as $row) {
            self::assertStringContainsString(
                'taart',
                (string) $row['contentNL'],
            );
        }
    }

    public function testCommentLinesAreIgnored(): void
    {
        $meeting = $this->build->meeting();

        $rows = $this->service->execute(
            "-- een vergadering\nSELECT m.number FROM db:Meeting m WHERE m.number = " . $meeting->getNumber(),
        );

        self::assertCount(
            1,
            $rows,
        );
        self::assertSame(
            $meeting->getNumber(),
            $rows[0]['number'],
        );
    }

    public function testAnEmptyQueryIsRefused(): void
    {
        $this->expectException(ORMException::class);

        $this->service->execute('');
    }

    public function testAnInvalidQueryIsRefused(): void
    {
        $this->expectException(ORMException::class);

        $this->service->execute('SELECT FROM db:Member m');
    }

    /**
     * The console is a read surface over the application connection, so a query that writes is refused before it is
     * ever sent.
     */
    public function testAWritingQueryIsRefused(): void
    {
        $this->expectException(ORMException::class);
        $this->expectExceptionMessageIsOrContains('The query console only runs SELECT statements.');

        $this->service->execute('UPDATE db:Member m SET m.hidden = true');
    }

    /**
     * Only the entities the page lists beside the editor may be addressed; the manager maps the whole site.
     */
    public function testAnEntityThatIsNotQueryableIsRefused(): void
    {
        $this->expectException(ORMException::class);
        $this->expectExceptionMessageIsOrContains('is not one of them');

        $this->service->execute('SELECT d FROM App\Entity\Decision\MeetingDocument d');
    }

    public function testGetEntitiesListsOnlyWhatTheConsoleMayQuery(): void
    {
        $entities = $this->service->getEntities();

        self::assertContains(
            'db:Member',
            $entities,
        );
        self::assertContains(
            'db:Organ',
            $entities,
        );
        self::assertNotContains(
            'db:MeetingDocument',
            $entities,
        );
    }

    private function ledgerCountByName(string $name): int
    {
        return $this->ledger->getRepository(SavedQuery::class)->count(['name' => $name]);
    }
}
