<?php

declare(strict_types=1);

namespace App\Tests\Command\Activity;

use App\Command\Activity\SyncAgendaCommand;
use App\Service\Activity\AgendaFeed;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function json_encode;

/**
 * The command is the only place the agenda is fetched. A successful run replaces the cached copy; a failed fetch
 * leaves the current copy unchanged.
 */
#[CoversClass(SyncAgendaCommand::class)]
final class SyncAgendaCommandTest extends TestCase
{
    public function testAFetchedAgendaIsKeptAndCounted(): void
    {
        $fetch = new MockHttpClient($this->agendaWith([
            [
                'summary' => 'Tentamenweek',
                'start' => ['date' => '2026-11-09'],
                'end' => ['date' => '2026-11-14'],
            ],
            [
                'summary' => 'Borrel',
                'start' => ['dateTime' => '2026-11-19T16:30:00+01:00'],
                'end' => ['dateTime' => '2026-11-19T20:00:00+01:00'],
            ],
        ]));
        $feed = $this->feed($fetch);

        $tester = new CommandTester(new SyncAgendaCommand(
            $feed,
            self::createStub(LoggerInterface::class),
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'Kept 2 agenda item(s).',
            $tester->getDisplay(),
        );
        self::assertCount(
            2,
            $feed->eventsBetween(
                new DateTimeImmutable('2026-11-01'),
                new DateTimeImmutable('2026-11-30'),
            ),
        );
    }

    /**
     * A failed fetch is not a failed run: a warning is logged, the cached copy stays, and the next scheduled run
     * follows.
     */
    public function testAFailedFetchKeepsWhatWasThere(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('The association agenda could not be fetched; the last copy is kept.');

        $feed = $this->feed(new MockHttpClient(new MockResponse(
            '',
            ['http_code' => 503],
        )));

        $tester = new CommandTester(new SyncAgendaCommand($feed, $logger));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'The agenda could not be fetched.',
            $tester->getDisplay(),
        );
    }

    /**
     * A machine without a key renders the calendar without this layer and makes no network request.
     */
    public function testNothingIsFetchedWithoutAnAgenda(): void
    {
        $fetch = new MockHttpClient([]);

        $tester = new CommandTester(new SyncAgendaCommand(
            new AgendaFeed(
                $fetch,
                new ArrayAdapter(),
                'unknown',
                'unknown',
            ),
            self::createStub(LoggerInterface::class),
        ));

        self::assertSame(
            Command::SUCCESS,
            $tester->execute([]),
        );
        self::assertStringContainsString(
            'No agenda is configured,',
            $tester->getDisplay(),
        );
        self::assertSame(
            0,
            $fetch->getRequestsCount(),
        );
    }

    private function feed(MockHttpClient $client): AgendaFeed
    {
        // Each case gets its own cache, so a fetch that never happened cannot pass on another case's data.
        return new AgendaFeed(
            $client,
            new ArrayAdapter(),
            'gewis-agenda',
            'a-key',
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function agendaWith(array $items): MockResponse
    {
        return new MockResponse(
            (string) json_encode(['items' => $items]),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }
}
