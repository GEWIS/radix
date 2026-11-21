<?php

declare(strict_types=1);

namespace App\Tests\Service\Database;

use App\Entity\Database\Enums\ConfigNamespaces;
use App\Entity\Database\MailingList;
use App\Entity\Database\MailingListMember;
use App\Entity\Database\MailmanMailingList;
use App\Entity\Database\Member;
use App\Repository\Database\MailingListMemberRepository;
use App\Repository\Database\MailingListRepository;
use App\Repository\Database\MailmanMailingListRepository;
use App\Repository\Database\MemberRepository;
use App\Service\Application\Config;
use App\Service\Database\Audit;
use App\Service\Database\MailmanService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_filter;
use function array_map;
use function array_values;
use function json_encode;
use function parse_str;
use function str_contains;
use function str_ends_with;

/**
 * Mailman is the external truth for the membership lists, so a sync run must translate the pending flags into the
 * exact calls, and anything the API cannot report must stop the run instead of counting as no members.
 */
#[CoversClass(MailmanService::class)]
class MailmanServiceTest extends TestCase
{
    private const string VERSION = '3.1';

    public function testFetchingListsCreatesListsThatWereNotSeenBefore(): void
    {
        $repository = $this->createMock(MailmanMailingListRepository::class);
        $repository->method('find')->willReturnCallback(static function (string $id): ?MailmanMailingList {
            self::assertSame(
                'list-one',
                $id,
            );

            return null;
        });
        $repository->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (MailmanMailingList $list): void {
                self::assertSame(
                    'list-one',
                    $list->mailmanId,
                );
                self::assertSame(
                    'List One',
                    $list->name,
                );
            });

        $this->service(
            mailmanMailingListRepository: $repository,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_ends_with(
                $url,
                '/lists',
            )
                ? self::json([
                    'total_size' => 1,
                    'entries' => [
                        [
                            'list_id' => 'list-one',
                            'display_name' => 'List One',
                        ],
                    ],
                ])
                : self::unexpected(
                    $method,
                    $url,
                )),
        )->fetchMailingLists();
    }

    public function testFetchingListsUpdatesListsThatWereSeenBefore(): void
    {
        $existing = new MailmanMailingList();
        $existing->mailmanId = 'list-one';
        $existing->name = 'Old Name';
        $existing->setLastSeen(new DateTimeImmutable('-1 day'));

        $repository = $this->createMock(MailmanMailingListRepository::class);
        $repository->method('find')->willReturnCallback(static function (
            string $id,
        ) use ($existing): MailmanMailingList {
            self::assertSame(
                'list-one',
                $id,
            );

            return $existing;
        });
        $repository->expects(self::once())->method('persist')->with($existing);

        $this->service(
            mailmanMailingListRepository: $repository,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_ends_with(
                $url,
                '/lists',
            )
                ? self::json([
                    'total_size' => 1,
                    'entries' => [
                        [
                            'list_id' => 'list-one',
                            'display_name' => 'New Name',
                        ],
                    ],
                ])
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
        $repository = $this->createMock(MailmanMailingListRepository::class);
        $repository->expects(self::never())->method('persist');

        $this->service(
            mailmanMailingListRepository: $repository,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_ends_with(
                $url,
                '/lists',
            )
                ? self::json(['total_size' => 0, 'entries' => []])
                : self::unexpected(
                    $method,
                    $url,
                )),
        )->fetchMailingLists();
    }

    public function testTheActiveListsComeFromTheActiveRepositoryMethod(): void
    {
        $active = [$this->mailmanList('active')];

        $repository = $this->createMock(MailmanMailingListRepository::class);
        $repository->expects(self::once())->method('findActive')->willReturn($active);
        $repository->expects(self::never())->method('findAll');

        self::assertSame(
            $active,
            $this->service(mailmanMailingListRepository: $repository)->getMailingLists(),
        );
    }

    public function testAllListsComeFromTheUnfilteredRepositoryMethod(): void
    {
        $all = [
            $this->mailmanList('one'),
            $this->mailmanList('two'),
        ];

        $repository = $this->createMock(MailmanMailingListRepository::class);
        $repository->expects(self::once())->method('findAll')->willReturn($all);
        $repository->expects(self::never())->method('findActive');

        self::assertSame(
            $all,
            $this->service(mailmanMailingListRepository: $repository)->getMailingLists(false),
        );
    }

    public function testAListIsLookedUpByItsMailmanIdentifier(): void
    {
        $list = $this->mailmanList('list-one');

        $repository = $this->createMock(MailmanMailingListRepository::class);
        $repository->expects(self::once())
            ->method('find')
            ->with('list-one')
            ->willReturn($list);

        self::assertSame(
            $list,
            $this->service(mailmanMailingListRepository: $repository)->getMailingList('list-one'),
        );
    }

    public function testTheLastFetchTimeComesFromTheRepository(): void
    {
        $lastFetch = new DateTimeImmutable('-10 minutes');

        $repository = self::createStub(MailmanMailingListRepository::class);
        $repository->method('getLastFetchTime')->willReturn($lastFetch);

        self::assertSame(
            $lastFetch,
            $this->service(mailmanMailingListRepository: $repository)->getLastFetchTime(),
        );
    }

    public function testAFetchThatNeverSucceededIsOverdue(): void
    {
        $figures = $this->service()->getStatusFigures();

        self::assertNull($figures['mailmanLastFetch']);
        self::assertTrue($figures['mailmanLastFetchOverdue']);
        self::assertNull($figures['mailmanLastSync']);
    }

    public function testARecentFetchIsNotOverdue(): void
    {
        $repository = self::createStub(MailmanMailingListRepository::class);
        $repository->method('getLastFetchTime')->willReturn(new DateTimeImmutable('-5 minutes'));

        self::assertFalse(
            $this->service(mailmanMailingListRepository: $repository)->getStatusFigures()['mailmanLastFetchOverdue'],
        );
    }

    public function testAFetchOlderThanAnHourIsOverdue(): void
    {
        $repository = self::createStub(MailmanMailingListRepository::class);
        $repository->method('getLastFetchTime')->willReturn(new DateTimeImmutable('-2 hours'));

        self::assertTrue(
            $this->service(mailmanMailingListRepository: $repository)->getStatusFigures()['mailmanLastFetchOverdue'],
        );
    }

    public function testTheLastSyncIsReadFromTheConfiguration(): void
    {
        $lastSync = new DateTimeImmutable('-3 hours');

        self::assertSame(
            $lastSync,
            $this->service(config: $this->config([
                'database_mailman.lastSync' => $lastSync,
            ]))->getStatusFigures()['mailmanLastSync'],
        );
    }

    public function testTheSyncIsLockedWhileTheConfigurationSaysSo(): void
    {
        $locked = $this->config(['database_mailman.locked' => new DateTimeImmutable('+1 hour')]);
        $unlocked = $this->config(['database_mailman.locked' => new DateTimeImmutable('-1 hour')]);

        self::assertTrue($this->service(config: $locked)->isSyncLocked());
        self::assertFalse($this->service(config: $unlocked)->isSyncLocked());
    }

    /**
     * A completed run acquires the lock, touches every bound list, records the time and releases the lock.
     */
    public function testASyncRunLocksSyncsTheBoundListsAndUnlocksAgain(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);

        $config = $this->config();

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $config,
            httpClient: $this->healthyClient(),
        )->syncMembership();

        self::assertFalse($this->service(config: $config)->isSyncLocked());
        self::assertInstanceOf(
            DateTimeImmutable::class,
            $this->service(config: $config)->getStatusFigures()['mailmanLastSync'],
        );
    }

    public function testARunIsRefusedWhileAnotherProcessHoldsTheLock(): void
    {
        $service = $this->service(
            config: $this->config(['database_mailman.locked' => new DateTimeImmutable('+1 hour')]),
            httpClient: $this->healthyClient(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unable to acquire sync lock for Mailman sync: locked by other process.',
        );

        $service->syncMembership();
    }

    public function testRenewingALockThatWasReleasedIsRefused(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);

        // acquire asks twice, then reads it back once; the per-list renewal then finds it unlocked.
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
            'Unable to renew sync lock for Mailman sync: currently unlocked.',
        );

        $service->syncMembership();
    }

    public function testAnUnhealthyMailmanStopsTheSyncBeforeTheLockIsTaken(): void
    {
        $config = $this->createMock(Config::class);
        $config->expects(self::never())->method('setConfig');

        $service = $this->service(
            config: $config,
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => str_ends_with(
                $url,
                '/system/versions',
            )
                ? self::json(['api_version' => '2.0'])
                : self::unexpected(
                    $method,
                    $url,
                )),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Mailman API is not healthy when performing mailman operation',
        );

        $service->syncMembership();
    }

    public function testANonSuccessfulResponseIsReportedWithItsStatus(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Request failed with status code: 500',
        );

        $this->service(
            httpClient: $this->client(static fn (string $method, string $url): MockResponse => new MockResponse(
                '',
                ['http_code' => 500],
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
        $repository = $this->createMock(MailmanMailingListRepository::class);
        $repository->expects(self::never())->method('persist');

        $this->service(
            mailmanMailingListRepository: $repository,
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
     * A new subscription is a single POST; an empty body means Mailman accepted it.
     */
    public function testANewMemberIsPostedAndThePendingFlagIsCleared(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
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
                        str_ends_with(
                            $url,
                            '/members',
                        ) && 'POST' === $method => new MockResponse(
                            '',
                            ['http_code' => 201],
                        ),
                        str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
                        str_contains($url, '/members/find') => self::json(['total_size' => 0, 'entries' => []]),
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

        parse_str(
            $posts[0][2]['body'],
            $body,
        );
        self::assertSame(
            'new@gewis.test',
            $body['subscriber'],
        );
        self::assertSame(
            'board-list',
            $body['list_id'],
        );
        self::assertNotSame(
            '1',
            $body['send_welcome_message'],
        );
        self::assertFalse($membership->toBeCreated);
        self::assertTrue($membership->lastSyncSuccess);
        self::assertNotNull($membership->lastSyncOn);
    }

    public function testASubscriptionThatMailmanDoesNotAcceptIsMarkedAsFailed(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
        $membership = $this->membership(
            $list,
            'new@gewis.test',
        );
        $membership->toBeCreated = true;

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $this->config(),
            httpClient: $this->client(
                static fn (string $method, string $url): MockResponse => match (true) {
                    str_ends_with(
                        $url,
                        '/members',
                    ) && 'POST' === $method => new MockResponse(
                        '{"error": "nope"}',
                        ['http_code' => 201],
                    ),
                str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
                str_contains($url, '/members/find') => self::json(['total_size' => 0, 'entries' => []]),
                default => self::unexpected(
                    $method,
                    $url,
                ),
                },
            ),
        )->syncMembership();

        self::assertTrue($membership->toBeCreated);
        self::assertFalse($membership->lastSyncSuccess);
    }

    /**
     * A dry run may ask Mailman what is on the list, but it must not change anything.
     */
    public function testADryRunWritesNothing(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
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
                static function (string $method, string $url) use (&$requests): MockResponse {
                    $requests[] = [
                        $method,
                        $url,
                    ];

                    return match (true) {
                        str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
                        str_contains($url, '/members/find') => self::json(['total_size' => 0, 'entries' => []]),
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
                static fn (array $request): bool => 'system/versions' !== $request[1] && str_contains(
                    $request[1],
                    'members/find',
                ),
                $requests,
            ),
            'A dry run still checks the list of known subscribers.',
        );
        foreach ($requests as [$method]) {
            self::assertNotSame(
                'POST',
                $method,
            );
            self::assertNotSame(
                'DELETE',
                $method,
            );
        }
    }

    /**
     * A pending deletion finds the member first and deletes it by its own identifier.
     */
    public function testAPendingDeletionRemovesTheMemberFromMailman(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
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
                        str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
                        str_contains(
                            $url,
                            '/members/find',
                        ) => self::json([
                            'total_size' => 1,
                            'entries' => [
                                [
                                    'member_id' => 'mm-1',
                                    'email' => 'gone@gewis.test',
                                ],
                            ],
                        ]),
                        str_ends_with(
                            $url,
                            '/members/mm-1',
                        ) && 'DELETE' === $method => new MockResponse(
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
                    '/members/mm-1',
                ),
                $requests,
            ),
        );
    }

    public function testADeletionThatMatchesMoreThanOneEntryIsRefused(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
        $membership = $this->membership(
            $list,
            'gone@gewis.test',
        );
        $membership->toBeDeleted = true;

        $service = $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            config: $this->config(),
            httpClient: $this->client(
                static fn (string $method, string $url): MockResponse => match (true) {
                    str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
                str_contains(
                    $url,
                    '/members/find',
                ) => self::json([
                    'total_size' => 2,
                    'entries' => [
                        [
                            'member_id' => 'a',
                            'email' => 'gone@gewis.test',
                        ],
                        [
                            'member_id' => 'b',
                            'email' => 'gone@gewis.test',
                        ],
                    ],
                ]),
                default => self::unexpected(
                    $method,
                    $url,
                ),
                },
            ),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            'Found more than one member gone@gewis.test with role member on list board-list',
        );

        $service->syncMembership();
    }

    /**
     * A member that is on the list is verified without a second lookup, because the known members already answered.
     */
    public function testAStaleMemberThatIsStillKnownIsOnlyMarkedAsSynced(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
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
                    str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
                str_contains(
                    $url,
                    '/members/find',
                ) => self::json([
                    'total_size' => 1,
                    'entries' => [
                        [
                            'member_id' => 'mm-1',
                            'email' => 'member@gewis.test',
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

    public function testAStaleMemberThatIsGoneFromMailmanIsRemovedFromTheRegister(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
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
                    str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
                str_contains($url, '/members/find') => self::json(['total_size' => 0, 'entries' => []]),
                default => self::unexpected(
                    $method,
                    $url,
                ),
                },
            ),
        )->syncMembership();
    }

    public function testAStaleMemberThatMailmanStillHasIsMarkedAsSynced(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $this->markCheckedRecently($list->mailmanList);
        $membership = $this->staleMembership(
            $list,
            'member@gewis.test',
        );

        $knownListQueries = 0;
        $memberRepository = $this->createMock(MailingListMemberRepository::class);
        $memberRepository->expects(self::once())->method('persist')->with($membership);

        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailingListMemberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(static function (
                string $method,
                string $url,
            ) use (&$knownListQueries): MockResponse {
                if (
                    str_ends_with(
                        $url,
                        '/system/versions',
                    )
                ) {
                    return self::json(['api_version' => self::VERSION]);
                }

                ++$knownListQueries;

                return 1 === $knownListQueries
                    ? self::json(['total_size' => 0, 'entries' => []])
                    : self::json(['total_size' => 1, 'entries' => [['member_id' => 'mm-1']]]);
            }),
        )->syncMembership();

        self::assertGreaterThan(
            new DateTimeImmutable('-1 minute'),
            $membership->lastSyncOn,
        );
    }

    /**
     * Once a day Mailman is the source: known addresses become memberships here, unknown ones are removed there.
     */
    public function testTheFullCheckAddsKnownAddressesAndRemovesUnknownOnes(): void
    {
        $list = $this->mailingList(
            'board',
            $this->mailmanList('board-list'),
        );
        $mailman = self::mailmanListOf($list);
        $mailman->setLastCheck(new DateTimeImmutable('-2 days'));

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

        $mailmanMailingListRepository = $this->createMock(MailmanMailingListRepository::class);
        $mailmanMailingListRepository->expects(self::once())
            ->method('persist')
            ->with($list->mailmanList);

        $deleted = [];
        $this->service(
            mailingListRepository: $this->listsRepository([$list]),
            mailmanMailingListRepository: $mailmanMailingListRepository,
            mailingListMemberRepository: $mailingListMemberRepository,
            memberRepository: $memberRepository,
            config: $this->config(),
            httpClient: $this->client(static function (string $method, string $url) use (&$deleted): MockResponse {
                if (
                    str_ends_with(
                        $url,
                        '/system/versions',
                    )
                ) {
                    return self::json(['api_version' => self::VERSION]);
                }

                if (
                    str_contains(
                        $url,
                        '/members/find',
                    )
                ) {
                    return self::json([
                        'total_size' => 2,
                        'entries' => [
                            [
                                'member_id' => 'mm-known',
                                'email' => 'known@gewis.test',
                            ],
                            [
                                'member_id' => 'mm-unknown',
                                'email' => 'unknown@gewis.test',
                            ],
                        ],
                    ]);
                }

                $deleted[] = [
                    $method,
                    $url,
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
        self::assertContains(
            true,
            array_map(
                static fn (array $request): bool => 'DELETE' === $request[0] && str_ends_with(
                    $request[1],
                    '/members/mm-unknown',
                ),
                $deleted,
            ),
        );
        self::assertNotNull(self::mailmanListOf($list)->lastCheck);
    }

    private function service(
        ?MailingListRepository $mailingListRepository = null,
        ?MailmanMailingListRepository $mailmanMailingListRepository = null,
        ?MailingListMemberRepository $mailingListMemberRepository = null,
        ?MemberRepository $memberRepository = null,
        ?Config $config = null,
        ?MockHttpClient $httpClient = null,
    ): MailmanService {
        return new MailmanService(
            $mailingListRepository ?? self::createStub(MailingListRepository::class),
            $mailmanMailingListRepository ?? self::createStub(MailmanMailingListRepository::class),
            $mailingListMemberRepository ?? self::createStub(MailingListMemberRepository::class),
            $memberRepository ?? self::createStub(MemberRepository::class),
            $config ?? $this->config(),
            self::createStub(Audit::class),
            $httpClient ?? $this->healthyClient(),
            'https://mailman.test/api/',
            self::VERSION,
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
                str_ends_with($url, '/system/versions') => self::json(['api_version' => self::VERSION]),
            str_contains($url, '/members/find') => self::json(['total_size' => 0, 'entries' => []]),
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
    private static function json(array $data): MockResponse
    {
        return new MockResponse(
            (string) json_encode($data),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    private static function unexpected(
        string $method,
        string $url,
    ): MockResponse {
        return new MockResponse(
            '',
            [
                'http_code' => 404,
                'response_headers' => ['content-type' => 'application/json'],
            ],
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
        MailmanMailingList $mailmanList,
    ): MailingList {
        $list = new MailingList();
        $list->name = $name;
        $list->onForm = true;
        $list->defaultSub = false;
        $list->setEnDescription('English ' . $name);
        $list->setNlDescription('Nederlands ' . $name);
        $list->mailmanList = $mailmanList;

        return $list;
    }

    private function mailmanList(string $id): MailmanMailingList
    {
        $list = new MailmanMailingList();
        $list->mailmanId = $id;
        $list->name = 'Mailman ' . $id;
        $list->setLastSeen();

        return $list;
    }

    private function markCheckedRecently(?MailmanMailingList $list): void
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

    private static function mailmanListOf(MailingList $list): MailmanMailingList
    {
        $mailmanList = $list->mailmanList;
        self::assertNotNull($mailmanList);

        return $mailmanList;
    }
}
