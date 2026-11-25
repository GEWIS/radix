<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Entity\Database\Enums\ConfigNamespaces;
use App\Entity\Database\ListmonkMailingList;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\Member;
use App\Repository\Database\ListmonkMailingListRepository;
use App\Repository\Database\MailingListMemberRepository;
use App\Repository\Database\MailingListRepository;
use App\Repository\Database\MemberRepository;
use App\Service\Application\Config;
use App\Service\Database\Audit;
use App\Service\Database\ListmonkService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_any;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_url;
use function str_contains;
use function str_ends_with;

use const PHP_URL_PATH;

/**
 * Listmonk has one subscriber record that may be on several lists, so subscribing and unsubscribing are two different
 * calls and the register has to know when the last list membership is gone.
 */
#[CoversClass(ListmonkService::class)]
class ListmonkServiceTest extends TestCase
{
    public function testFetchingListsCreatesListsThatWereNotSeenBefore(): void
    {
        $repository = $this->createMock(ListmonkMailingListRepository::class);
        $repository->method('find')->willReturnCallback(static function (int $id): ?ListmonkMailingList {
            self::assertSame(
                1,
                $id,
            );

            return null;
        });
        $repository->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (ListmonkMailingList $list): void {
                self::assertSame(
                    1,
                    $list->listmonkId,
                );
                self::assertSame(
                    'List One',
                    $list->name,
                );
            });

        $this->service(
            listmonkMailingListRepository: $repository,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_contains(
                $url,
                '/lists',
            )
                ? self::json(['data' => ['total' => 1, 'results' => [['id' => 1, 'name' => 'List One']]]])
                : self::unexpected(
                    $method,
                    $url,
                )),
        )->fetchMailingLists();
    }

    public function testFetchingListsUpdatesListsThatWereSeenBefore(): void
    {
        $existing = $this->listmonkList(
            1,
            'Old Name',
        );
        $existing->setLastSeen(new DateTimeImmutable('-1 day'));

        $repository = $this->createMock(ListmonkMailingListRepository::class);
        $repository->method('find')->willReturnCallback(static function (
            int $id,
        ) use ($existing): ListmonkMailingList {
            self::assertSame(
                1,
                $id,
            );

            return $existing;
        });
        $repository->expects(self::once())->method('persist')->with($existing);

        $this->service(
            listmonkMailingListRepository: $repository,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_contains(
                $url,
                '/lists',
            )
                ? self::json(['data' => ['total' => 1, 'results' => [['id' => 1, 'name' => 'New Name']]]])
                : self::unexpected(
                    $method,
                    $url,
                )),
        )->fetchMailingLists();

        self::assertSame(
            'New Name',
            $existing->name,
        );
        self::assertGreaterThan(
            new DateTimeImmutable('-1 minute'),
            $existing->lastSeen,
        );
    }

    public function testAnEmptyListResponseWritesNothing(): void
    {
        $repository = $this->createMock(ListmonkMailingListRepository::class);
        $repository->expects(self::never())->method('persist');

        $this->service(
            listmonkMailingListRepository: $repository,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_contains(
                $url,
                '/lists',
            )
                ? self::json(['data' => ['total' => 0, 'results' => []]])
                : self::unexpected(
                    $method,
                    $url,
                )),
        )->fetchMailingLists();
    }

    public function testTheActiveListsComeFromTheActiveRepositoryMethod(): void
    {
        $active = [
            $this->listmonkList(
                1,
                'active',
            ),
        ];

        $repository = $this->createMock(ListmonkMailingListRepository::class);
        $repository->expects(self::once())->method('findActive')->willReturn($active);
        $repository->expects(self::never())->method('findAll');

        self::assertSame(
            $active,
            $this->service(listmonkMailingListRepository: $repository)->getMailingLists(),
        );
    }

    public function testAllListsComeFromTheUnfilteredRepositoryMethod(): void
    {
        $all = [
            $this->listmonkList(
                1,
                'one',
            ),
            $this->listmonkList(
                2,
                'two',
            ),
        ];

        $repository = $this->createMock(ListmonkMailingListRepository::class);
        $repository->expects(self::once())->method('findAll')->willReturn($all);
        $repository->expects(self::never())->method('findActive');

        self::assertSame(
            $all,
            $this->service(listmonkMailingListRepository: $repository)->getMailingLists(false),
        );
    }

    public function testAListIsLookedUpByItsListmonkIdentifier(): void
    {
        $list = $this->listmonkList(
            7,
            'seven',
        );

        $repository = $this->createMock(ListmonkMailingListRepository::class);
        $repository->expects(self::once())->method('find')->with(7)->willReturn($list);

        self::assertSame(
            $list,
            $this->service(listmonkMailingListRepository: $repository)->getMailingList(7),
        );
    }

    public function testTheLastFetchTimeComesFromTheRepository(): void
    {
        $lastFetch = new DateTimeImmutable('-10 minutes');

        $repository = self::createStub(ListmonkMailingListRepository::class);
        $repository->method('getLastFetchTime')->willReturn($lastFetch);

        self::assertSame(
            $lastFetch,
            $this->service(listmonkMailingListRepository: $repository)->getLastFetchTime(),
        );
    }

    public function testAFetchThatNeverSucceededIsOverdue(): void
    {
        $figures = $this->service()->getStatusFigures();

        self::assertNull($figures['listmonkLastFetch']);
        self::assertTrue($figures['listmonkLastFetchOverdue']);
        self::assertNull($figures['listmonkLastSync']);
    }

    public function testARecentFetchIsNotOverdue(): void
    {
        $repository = self::createStub(ListmonkMailingListRepository::class);
        $repository->method('getLastFetchTime')->willReturn(new DateTimeImmutable('-5 minutes'));

        self::assertFalse(
            $this->service(listmonkMailingListRepository: $repository)->getStatusFigures()['listmonkLastFetchOverdue'],
        );
    }

    public function testAFetchOlderThanAnHourIsOverdue(): void
    {
        $repository = self::createStub(ListmonkMailingListRepository::class);
        $repository->method('getLastFetchTime')->willReturn(new DateTimeImmutable('-2 hours'));

        self::assertTrue(
            $this->service(listmonkMailingListRepository: $repository)->getStatusFigures()['listmonkLastFetchOverdue'],
        );
    }

    public function testTheLastSyncIsReadFromTheConfiguration(): void
    {
        $lastSync = new DateTimeImmutable('-3 hours');

        self::assertSame(
            $lastSync,
            $this->service(config: $this->config([
                'database_listmonk.lastSync' => $lastSync,
            ]))->getStatusFigures()['listmonkLastSync'],
        );
    }

    public function testTheSyncIsLockedWhileTheConfigurationSaysSo(): void
    {
        self::assertTrue($this->service(config: $this->config([
            'database_listmonk.locked' => new DateTimeImmutable('+1 hour'),
        ]))->isSyncLocked());

        self::assertFalse($this->service(config: $this->config([
            'database_listmonk.locked' => new DateTimeImmutable('-1 hour'),
        ]))->isSyncLocked());
    }

    public function testASyncRunLocksSyncsTheBoundListsAndUnlocksAgain(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                1,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);

        $config = $this->config();

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $config,
            httpClient: $this->healthyClient(),
        )->syncMembership();

        self::assertFalse($this->service(config: $config)->isSyncLocked());
        self::assertInstanceOf(
            DateTimeImmutable::class,
            $this->service(config: $config)->getStatusFigures()['listmonkLastSync'],
        );
    }

    public function testARunIsRefusedWhileAnotherProcessHoldsTheLock(): void
    {
        $service = $this->service(
            config: $this->config(['database_listmonk.locked' => new DateTimeImmutable('+1 hour')]),
            httpClient: $this->healthyClient(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unable to acquire sync lock for Listmonk sync: locked by other process.',
        );

        $service->syncMembership();
    }

    public function testRenewingALockThatWasReleasedIsRefused(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                1,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);

        $reads = 0;
        $config = self::createStub(Config::class);
        $config->method('getConfig')->willReturnCallback(
            static function () use (&$reads): bool|DateTimeImmutable {
                return 3 === ++$reads
                    ? new DateTimeImmutable('+1 hour')
                    : false;
            },
        );

        $service = $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $config,
            httpClient: $this->healthyClient(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unable to renew sync lock for Listmonk sync: currently unlocked.',
        );

        $service->syncMembership();
    }

    public function testAnUnhealthyListmonkStopsTheSyncBeforeTheLockIsTaken(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::never())->method('setConfig');

        $service = $this->service(
            config: $config,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_ends_with(
                self::path($url),
                '/health',
            )
                ? self::json(['data' => false])
                : self::unexpected(
                    $method,
                    $url,
                )),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Listmonk API is not healthy when performing listmonk operation',
        );

        $service->syncMembership();
    }

    public function testANonSuccessfulResponseIsReportedWithItsStatus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Request failed with status code: 503',
        );

        $this->service(
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => new MockResponse(
                '',
                ['http_code' => 503],
            )),
        )->fetchMailingLists();
    }

    public function testAResponseThatIsNotJsonIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Failed to parse JSON response:',
        );

        $this->service(
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => new MockResponse(
                'not json',
            )),
        )->fetchMailingLists();
    }

    public function testAnEmptyBodyIsAnEmptyResult(): void
    {
        $repository = $this->createMock(ListmonkMailingListRepository::class);
        $repository->expects(self::never())->method('persist');

        $this->service(
            listmonkMailingListRepository: $repository,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => new MockResponse(
                '',
                ['http_code' => 204],
            )),
        )->fetchMailingLists();
    }

    public function testATransportFailureIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Failed to send request: connection refused',
        );

        $this->service(
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => new MockResponse(
                '',
                ['error' => 'connection refused'],
            )),
        )->fetchMailingLists();
    }

    /**
     * Someone Listmonk has never heard of is created with the list attached in one call.
     */
    public function testANewSubscriberIsCreatedWithTheList(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->membership(
            $list,
            'new@gewis.test',
        );
        $membership->toBeCreated = true;

        $requests = [];
        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())->method('persist')->with($membership);

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailingListMemberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(
                static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                    $requests[] = [
                        $method,
                        $url,
                        $options,
                    ];

                    return match (true) {
                        str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => self::json(['data' => ['results' => []]]),
                        'POST' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => self::json(
                            ['data' => ['id' => 9]],
                            201,
                        ),
                        default => self::unexpected(
                            $method,
                            $url,
                        ),
                    };
                },
            ),
        )->syncMembership();

        $posts = array_values(array_filter(
            $requests,
            static fn (array $request): bool => 'POST' === $request[0],
        ));
        self::assertCount(
            1,
            $posts,
        );
        $body = json_decode(
            $posts[0][2]['body'],
            true,
        );
        self::assertSame(
            'new@gewis.test',
            $body['email'],
        );
        self::assertSame(
            [4],
            $body['lists'],
        );
        self::assertTrue($body['preconfirm_subscriptions']);
        self::assertFalse($membership->toBeCreated);
        self::assertTrue($membership->lastSyncSuccess);
        self::assertNotNull($membership->lastSyncOn);
    }

    /**
     * An existing subscriber is added to the list, which is its own endpoint and action.
     */
    public function testAnExistingSubscriberIsAddedToTheList(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->membership(
            $list,
            'member@gewis.test',
        );
        $membership->toBeCreated = true;

        $requests = [];
        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $this->config(),
            httpClient: $this->client(
                static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                    $requests[] = [
                        $method,
                        $url,
                        $options,
                    ];

                    return match (true) {
                        str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => self::json([
                            'data' => [
                                'results' => [
                                    [
                                        'id' => 12,
                                        'email' => 'member@gewis.test',
                                    ],
                                ],
                            ],
                        ]),
                        'PUT' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers/lists',
                        ) => new MockResponse(
                            '',
                            ['http_code' => 204],
                        ),
                        default => self::unexpected(
                            $method,
                            $url,
                        ),
                    };
                },
            ),
        )->syncMembership();

        $puts = array_values(array_filter(
            $requests,
            static fn (array $request): bool => 'PUT' === $request[0],
        ));
        self::assertCount(
            1,
            $puts,
        );
        $body = json_decode(
            $puts[0][2]['body'],
            true,
        );
        self::assertSame(
            'add',
            $body['action'],
        );
        self::assertSame(
            [12],
            $body['ids'],
        );
        self::assertSame(
            [4],
            $body['target_list_ids'],
        );
        self::assertSame(
            'confirmed',
            $body['status'],
        );
        self::assertFalse($membership->toBeCreated);
    }

    /**
     * The address is interpolated into Listmonk's SQL-like query language, so a quote in it is doubled.
     */
    public function testAnApostropheInAnAddressIsEscapedInTheQuery(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->membership(
            $list,
            "o'brien@gewis.test",
        );
        $membership->toBeCreated = true;

        $queries = [];
        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $this->config(),
            httpClient: $this->client(
                static function (string $method, string $url, array $options) use (&$queries): MockResponse {
                    if (isset($options['query']['query'])) {
                        $queries[] = $options['query']['query'];
                    }

                    return match (true) {
                        str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => self::json(['data' => ['results' => []]]),
                        'POST' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => self::json(
                            ['data' => ['id' => 9]],
                            201,
                        ),
                        default => self::unexpected(
                            $method,
                            $url,
                        ),
                    };
                },
            ),
        )->syncMembership();

        self::assertContains(
            "email='o''brien@gewis.test'",
            $queries,
        );
    }

    public function testADryRunWritesNothing(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->membership(
            $list,
            'new@gewis.test',
        );
        $membership->toBeCreated = true;

        $requests = [];
        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::never())->method('persist');

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailingListMemberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(
                static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                    $requests[] = [
                        $method,
                        $url,
                    ];

                    return match (true) {
                        str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                        'GET' === $method && str_contains(
                            $url,
                            '/subscribers',
                        ) => self::json(['data' => ['results' => []]]),
                        default => self::unexpected(
                            $method,
                            $url,
                        ),
                    };
                },
            ),
        )->syncMembership(
            new NullOutput(),
            true,
        );

        self::assertTrue($membership->toBeCreated);
        self::assertContains(
            true,
            array_map(
                static fn (array $request): bool => 'GET' === $request[0] && str_contains(
                    $request[1],
                    'list_id=4',
                ),
                $requests,
            ),
            'A dry run still reads the subscribers of the list.',
        );
        foreach ($requests as [$method]) {
            self::assertNotSame(
                'POST',
                $method,
            );
            self::assertNotSame(
                'PUT',
                $method,
            );
            self::assertNotSame(
                'DELETE',
                $method,
            );
        }
    }

    /**
     * Removing someone from one list leaves the subscriber alone while they are on another.
     */
    public function testUnsubscribingKeepsASubscriberThatIsStillOnOtherLists(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->membership(
            $list,
            'gone@gewis.test',
        );
        $membership->toBeDeleted = true;

        $requests = [];
        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())->method('remove')->with($membership);

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailingListMemberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(
                static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                    $requests[] = [
                        $method,
                        $url,
                    ];

                    return match (true) {
                        str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers/12',
                        ) => self::json(['data' => ['lists' => [4, 5]]]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => isset($options['query']['list_id'])
                            ? self::json(['data' => ['results' => []]])
                            : self::json(['data' => ['results' => [['id' => 12]]]]),
                        'PUT' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers/lists',
                        ) => new MockResponse(
                            '',
                            ['http_code' => 204],
                        ),
                        default => self::unexpected(
                            $method,
                            $url,
                        ),
                    };
                },
            ),
        )->syncMembership();

        self::assertContains(
            true,
            array_map(
                static fn (array $request): bool => 'PUT' === $request[0] && str_ends_with(
                    $request[1],
                    '/subscribers/lists',
                ),
                $requests,
            ),
        );
        self::assertFalse(
            array_any(
                $requests,
                static fn (array $request): bool => 'DELETE' === $request[0],
            ),
            'A subscriber on other lists is not deleted.',
        );
    }

    public function testUnsubscribingDeletesASubscriberThatIsOnNoListsLeft(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->membership(
            $list,
            'gone@gewis.test',
        );
        $membership->toBeDeleted = true;

        $requests = [];
        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $this->config(),
            httpClient: $this->client(
                static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                    $requests[] = [
                        $method,
                        $url,
                    ];

                    return match (true) {
                        str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers/12',
                        ) => self::json(['data' => ['lists' => []]]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => isset($options['query']['list_id'])
                            ? self::json(['data' => ['results' => []]])
                            : self::json(['data' => ['results' => [['id' => 12]]]]),
                        'PUT' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers/lists',
                        ) => new MockResponse(
                            '',
                            ['http_code' => 204],
                        ),
                        'DELETE' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers/12',
                        ) => new MockResponse(
                            '',
                            ['http_code' => 204],
                        ),
                        default => self::unexpected(
                            $method,
                            $url,
                        ),
                    };
                },
            ),
        )->syncMembership();

        self::assertContains(
            true,
            array_map(
                static fn (array $request): bool => 'DELETE' === $request[0] && str_ends_with(
                    $request[1],
                    '/subscribers/12',
                ),
                $requests,
            ),
        );
    }

    public function testUnsubscribingAnAddressThatListmonkDoesNotKnowOnlyRemovesTheLocalMembership(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->membership(
            $list,
            'gone@gewis.test',
        );
        $membership->toBeDeleted = true;

        $requests = [];
        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())->method('remove')->with($membership);

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailingListMemberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(
                static function (string $method, string $url) use (&$requests): MockResponse {
                    $requests[] = [
                        $method,
                        $url,
                    ];

                    return match (true) {
                        str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                        'GET' === $method && str_ends_with(
                            self::path($url),
                            '/subscribers',
                        ) => self::json(['data' => ['results' => []]]),
                        default => self::unexpected(
                            $method,
                            $url,
                        ),
                    };
                },
            ),
        )->syncMembership();

        self::assertFalse(
            array_any(
                $requests,
                static fn (array $request): bool => in_array(
                    $request[0],
                    [
                        'PUT',
                        'DELETE',
                    ],
                    true,
                ),
            ),
        );
    }

    public function testAStaleMemberThatIsStillOnTheListIsOnlyMarkedAsSynced(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->staleMembership(
            $list,
            'member@gewis.test',
        );

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())->method('persist')->with($membership);

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailingListMemberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(
                static fn (string $method, string $url): MockResponse => match (true) {
                    str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                'GET' === $method && str_contains(
                    $url,
                    '/subscribers',
                ) => self::json([
                    'data' => [
                        'results' => [
                            [
                                'id' => 1,
                                'email' => 'member@gewis.test',
                            ],
                        ],
                    ],
                ]),
                default => self::unexpected(
                    $method,
                    $url,
                ),
                },
            ),
        )->syncMembership();

        self::assertGreaterThan(
            new DateTimeImmutable('-1 minute'),
            $membership->lastSyncOn,
        );
    }

    public function testAStaleMemberThatIsGoneFromTheListIsRemovedFromTheRegister(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $this->markCheckedRecently($list->listmonkList);
        $membership = $this->staleMembership(
            $list,
            'gone@gewis.test',
        );

        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())->method('remove')->with($membership);

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailingListMemberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(
                static fn (string $method, string $url): MockResponse => match (true) {
                    str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
                'GET' === $method && str_contains($url, '/subscribers') => self::json(['data' => ['results' => []]]),
                default => self::unexpected(
                    $method,
                    $url,
                ),
                },
            ),
        )->syncMembership();
    }

    /**
     * The daily full check adds addresses Listmonk knows and takes the ones it does not off the list.
     */
    public function testTheFullCheckAddsKnownAddressesAndRemovesUnknownOnes(): void
    {
        $list = $this->mailingList(
            'board',
            $this->listmonkList(
                4,
                'board-list',
            ),
        );
        $listmonk = self::listmonkListOf($list);
        $listmonk->setLastCheck(new DateTimeImmutable('-2 days'));

        $member = $this->member('known@gewis.test');

        $memberRepository = self::createStub(MemberRepository::class);
        $memberRepository->method('findByEmail')->willReturnCallback(
            static fn (string $email): ?Member => 'known@gewis.test' === $email ? $member : null,
        );

        $added = null;
        $mailingListMemberRepository = self::createStub(MailingListMemberRepository::class);
        $mailingListMemberRepository->method('persist')->willReturnCallback(
            static function (MailingListMember $membership) use (&$added): void {
                $added = $membership;
            },
        );

        $listmonkMailingListRepository = $this->createMock(ListmonkMailingListRepository::class);
        $listmonkMailingListRepository->expects(self::once())
            ->method('persist')
            ->with($list->listmonkList);

        $removals = [];
        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            listmonkMailingListRepository: $listmonkMailingListRepository,
            mailingListMemberRepository: $mailingListMemberRepository,
            memberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(static function (
                string $method,
                string $url,
                array $options,
            ) use (&$removals): MockResponse {
                if (
                    str_ends_with(
                        self::path($url),
                        '/health',
                    )
                ) {
                    return self::json(['data' => true]);
                }

                if (
                    'GET' === $method
                    && str_contains(
                        $url,
                        '/subscribers',
                    )
                ) {
                    return self::json([
                        'data' => [
                            'results' => [
                                [
                                    'id' => 1,
                                    'email' => 'known@gewis.test',
                                ],
                                [
                                    'id' => 2,
                                    'email' => 'unknown@gewis.test',
                                ],
                            ],
                        ],
                    ]);
                }

                $removals[] = [
                    $method,
                    $url,
                    $options,
                ];

                return new MockResponse(
                    '',
                    ['http_code' => 204],
                );
            }),
        )->syncMembership();

        self::assertNotNull($added);
        self::assertSame(
            'known@gewis.test',
            $added->email,
        );
        self::assertFalse($added->toBeCreated);
        self::assertSame(
            $member,
            $added->member,
        );
        self::assertCount(
            1,
            $removals,
        );
        self::assertSame(
            'PUT',
            $removals[0][0],
        );

        $body = json_decode(
            $removals[0][2]['body'],
            true,
        );
        self::assertSame(
            'remove',
            $body['action'],
        );
        self::assertSame(
            [2],
            $body['ids'],
        );
        self::assertSame(
            [4],
            $body['target_list_ids'],
        );
        self::assertNotNull(self::listmonkListOf($list)->lastCheck);
    }

    private function service(
        ?MailingListRepository $mailingListRepository = null,
        ?ListmonkMailingListRepository $listmonkMailingListRepository = null,
        ?MailingListMemberRepository $mailingListMemberRepository = null,
        ?MemberRepository $memberRepository = null,
        ?Config $config = null,
        ?MockHttpClient $httpClient = null,
    ): ListmonkService {
        return new ListmonkService(
            $mailingListRepository ?? self::createStub(MailingListRepository::class),
            $listmonkMailingListRepository ?? self::createStub(ListmonkMailingListRepository::class),
            $mailingListMemberRepository ?? self::createStub(MailingListMemberRepository::class),
            $memberRepository ?? self::createStub(MemberRepository::class),
            self::createStub(Audit::class),
            $config ?? $this->config(),
            $httpClient ?? $this->healthyClient(),
            'https://listmonk.test/api/',
            'user',
            'password',
        );
    }

    /**
     * @param array<string, bool|string|DateTimeImmutable> $initial
     */
    private function config(array $initial = []): Config
    {
        $values = $initial;

        $config = self::createStub(Config::class);
        $config->method('getConfig')->willReturnCallback(
            static function (
                ConfigNamespaces $namespace,
                string $key,
                bool|string|DateTimeImmutable|null $default = null,
            ) use (&$values): bool|string|DateTimeImmutable|null {
                return $values[$namespace->value . '.' . $key] ?? $default;
            },
        );
        $config->method('setConfig')->willReturnCallback(
            static function (
                ConfigNamespaces $namespace,
                string $key,
                bool|string|DateTimeImmutable $value,
            ) use (&$values): void {
                $values[$namespace->value . '.' . $key] = $value;
            },
        );

        return $config;
    }

    private function healthyClient(): MockHttpClient
    {
        return $this->client(
            static fn (string $method, string $url): MockResponse => match (true) {
                str_ends_with(self::path($url), '/health') => self::json(['data' => true]),
            'GET' === $method && str_ends_with(
                self::path($url),
                '/subscribers',
            ) => self::json(['data' => ['results' => []]]),
            default => self::unexpected(
                $method,
                $url,
            ),
            },
        );
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse $handler
     */
    private function client(callable $handler): MockHttpClient
    {
        return new MockHttpClient(
            static function (string $method, string $url, array $options) use ($handler): MockResponse {
                return $handler($method, $url, $options);
            },
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(
        array $data,
        int $statusCode = 200,
    ): MockResponse {
        return new MockResponse(
            (string) json_encode($data),
            [
                'http_code' => $statusCode,
                'response_headers' => ['content-type' => 'application/json'],
            ],
        );
    }

    private static function path(string $url): string
    {
        $path = parse_url(
            $url,
            PHP_URL_PATH,
        );

        return is_string($path)
            ? $path
            : '';
    }

    private static function unexpected(
        string $method,
        string $url,
    ): MockResponse {
        return new MockResponse(
            '',
            ['http_code' => 404],
        );
    }

    /**
     * @param MailingList[] $lists
     */
    private function listsRepository(array $lists): MailingListRepository
    {
        $repository = self::createStub(MailingListRepository::class);
        $repository->method('findAll')->willReturn($lists);

        return $repository;
    }

    private function mailingList(
        string $name,
        ListmonkMailingList $listmonkList,
    ): MailingList {
        $list = new MailingList();
        $list->name = $name;
        $list->onForm = true;
        $list->defaultSub = false;
        $list->setEnDescription('English ' . $name);
        $list->setNlDescription('Nederlands ' . $name);
        $list->listmonkList = $listmonkList;

        return $list;
    }

    private function listmonkList(
        int $id,
        string $name,
    ): ListmonkMailingList {
        $list = new ListmonkMailingList();
        $list->listmonkId = $id;
        $list->name = $name;
        $list->setLastSeen();

        return $list;
    }

    private function markCheckedRecently(?ListmonkMailingList $list): void
    {
        self::assertNotNull($list);
        $list->setLastCheck(new DateTimeImmutable('-1 hour'));
    }

    private function membership(
        MailingList $list,
        string $email,
    ): MailingListMember {
        $membership = new MailingListMember();
        $membership->mailingList = $list;
        $membership->setMember($this->member($email));
        $membership->email = $email;
        $membership->setLastSyncOn(new DateTimeImmutable('-2 hours'));

        $list->getMailingListMemberships()->add($membership);

        return $membership;
    }

    private function staleMembership(
        MailingList $list,
        string $email,
    ): MailingListMember {
        $membership = $this->membership(
            $list,
            $email,
        );
        $membership->toBeCreated = false;
        $membership->setLastSyncOn(new DateTimeImmutable('-2 days'));

        return $membership;
    }

    private function member(string $email): Member
    {
        $member = new Member();
        $member->firstName = 'Test';
        $member->middleName = '';
        $member->lastName = 'Member';
        $member->initials = 'T';
        $member->setEmail($email);

        return $member;
    }

    private static function listmonkListOf(MailingList $list): ListmonkMailingList
    {
        $listmonkList = $list->listmonkList;
        self::assertNotNull($listmonkList);

        return $listmonkList;
    }
}
