<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\ApiResource\Decision\MailingList;
use App\ApiResource\Decision\MailingListMember;
use App\Entity\Decision\MailingList as ProjectedMailingList;
use App\Entity\Decision\MailingListMember as ProjectedMailingListMember;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Decision\MailingListMemberProvider;
use App\State\Decision\MailingListProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_keys;
use function strtoupper;
use function strtr;

#[CoversClass(MailingList::class)]
#[CoversClass(MailingListMember::class)]
#[CoversClass(MailingListProvider::class)]
#[CoversClass(MailingListMemberProvider::class)]
final class MailingListApiTest extends ApiTestCase
{
    private const array LIST_KEYS = [
        'name',
        'description',
    ];

    private const array DESCRIPTION_KEYS = [
        'en',
        'nl',
    ];

    private const array SUBSCRIBER_KEYS = [
        'lidnr',
        'full_name',
        'email',
    ];

    private const array META_KEYS = [
        'page',
        'itemsPerPage',
        'totalItems',
        'totalPages',
    ];

    #[DataProvider('gatedOperations')]
    public function testAnOperationIsClosedToAPrincipalThatDoesNotHoldItsPermission(
        string $path,
        ApiPermissions $permission,
    ): void {
        $response = $this->get(
            $this->resolve($path),
            $this->principalWith([ApiPermissions::HealthR]),
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => 'forbidden',
                'error' => [
                    'type' => 'User\\Model\\Exception\\NotAllowed',
                    'exception' => 'Permission `' . $permission->value . '` is needed but is not currently held.',
                ],
            ],
            $this->json($response),
        );
    }

    #[DataProvider('gatedPaths')]
    public function testAnOperationAnswersNothingAtAllWithoutAToken(string $path): void
    {
        $response = $this->get($this->resolve($path));

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );
        self::assertSame(
            '',
            (string) $response->getContent(),
        );
    }

    #[DataProvider('gatedOperations')]
    public function testAnOperationExpectsTheVersionedContract(
        string $path,
        ApiPermissions $permission,
    ): void {
        $response = $this->get(
            $this->resolve($path),
            $this->principalWith([$permission]),
            withVersion: false,
        );

        self::assertSame(
            Response::HTTP_NOT_ACCEPTABLE,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => 'error',
                'error' => [
                    'type' => 'Database\\Model\\Exception\\VersionExpected',
                    'exception' => 'API version expected, but none was given',
                ],
            ],
            $this->json($response),
        );
    }

    /**
     * @return iterable<string, array{string, ApiPermissions}>
     */
    public static function gatedOperations(): iterable
    {
        yield 'the mailing lists' => [
            '/api/mailing-lists',
            ApiPermissions::MailingListsR,
        ];

        yield 'one mailing list' => [
            '/api/mailing-lists/{name}',
            ApiPermissions::MailingListsR,
        ];

        yield 'the subscribers of a mailing list' => [
            '/api/mailing-lists/{name}/members',
            ApiPermissions::MailingListMembersR,
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gatedPaths(): iterable
    {
        foreach (self::gatedOperations() as $name => $operation) {
            yield $name => [$operation[0]];
        }
    }

    public function testAMailingListRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $response = $this->get(
            '/api/mailing-lists',
            $this->principalWith([ApiPermissions::MailingListsR]),
            ['itemsPerPage' => 1],
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertSame(
            self::LIST_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::DESCRIPTION_KEYS,
            array_keys($body['data'][0]['description']),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testASingleMailingListIsTheSameRowAndCarriesNoPage(): void
    {
        $name = $this->aMailingListName();

        $response = $this->get(
            '/api/mailing-lists/' . $name,
            $this->principalWith([ApiPermissions::MailingListsR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            self::LIST_KEYS,
            array_keys($body['data']),
        );
        self::assertSame(
            $name,
            $body['data']['name'],
        );
        self::assertArrayNotHasKey(
            'meta',
            $body,
        );
    }

    public function testTheListPagesThroughTheMailingLists(): void
    {
        $token = $this->principalWith([ApiPermissions::MailingListsR]);

        $first = $this->json($this->get(
            '/api/mailing-lists',
            $token,
            ['itemsPerPage' => 1],
        ));
        $second = $this->json($this->get(
            '/api/mailing-lists',
            $token,
            [
                'itemsPerPage' => 1,
                'page' => 2,
            ],
        ));

        self::assertCount(
            1,
            $first['data'],
        );
        self::assertSame(
            1,
            $first['meta']['page'],
        );
        self::assertSame(
            2,
            $second['meta']['page'],
        );
        self::assertSame(
            $first['meta']['totalItems'],
            $second['meta']['totalItems'],
        );
        self::assertNotSame(
            $first['data'][0]['name'],
            $second['data'][0]['name'],
        );
    }

    public function testAPageOutOfRangeIsClampedRatherThanRefused(): void
    {
        $token = $this->principalWith([ApiPermissions::MailingListsR]);

        $tooLarge = $this->json($this->get(
            '/api/mailing-lists',
            $token,
            ['itemsPerPage' => 100000],
        ));
        self::assertSame(
            500,
            $tooLarge['meta']['itemsPerPage'],
        );

        $tooSmall = $this->json($this->get(
            '/api/mailing-lists',
            $token,
            [
                'itemsPerPage' => 0,
                'page' => -3,
            ],
        ));
        self::assertSame(
            1,
            $tooSmall['meta']['page'],
        );
        self::assertSame(
            1,
            $tooSmall['meta']['itemsPerPage'],
        );

        $pastTheEnd = $this->get(
            '/api/mailing-lists',
            $token,
            ['page' => 99999],
        );
        self::assertSame(
            Response::HTTP_OK,
            $pastTheEnd->getStatusCode(),
        );
        self::assertSame(
            [],
            $this->json($pastTheEnd)['data'],
        );
    }

    public function testAnUnparseableItemsPerPageFallsBackToTheDefaultRatherThanRefusing(): void
    {
        $response = $this->get(
            '/api/mailing-lists',
            $this->principalWith([ApiPermissions::MailingListsR]),
            ['itemsPerPage' => 'plenty'],
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            100,
            $this->json($response)['meta']['itemsPerPage'],
        );
    }

    public function testAMailingListNoListIsStoredUnderIsAMissingResource(): void
    {
        $token = $this->principalWith([
            ApiPermissions::MailingListsR,
            ApiPermissions::MailingListMembersR,
        ]);

        foreach (
            [
                '/api/mailing-lists/no-such-list',
                '/api/mailing-lists/no-such-list/members',
            ] as $path
        ) {
            $response = $this->get(
                $path,
                $token,
            );

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $response->getStatusCode(),
                $path,
            );
            self::assertSame(
                [
                    'status' => 'notfound',
                    'error' => [
                        'type' => 'error-resource-not-found',
                        'exception' => 'Not Found',
                    ],
                ],
                $this->json($response),
                $path,
            );
        }
    }

    #[DataProvider('namesTheRouteRefuses')]
    public function testANameTheRouteRefusesReachesNoOperationAtAll(string $name): void
    {
        $token = $this->principalWith([
            ApiPermissions::MailingListsR,
            ApiPermissions::MailingListMembersR,
        ]);

        foreach (
            [
                '/api/mailing-lists/' . $name,
                '/api/mailing-lists/' . $name . '/members',
            ] as $path
        ) {
            $response = $this->get(
                $path,
                $token,
            );

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $response->getStatusCode(),
                $path,
            );
            self::assertSame(
                [
                    'status' => 'notfound',
                    'error' => [
                        'type' => 'error-router-no-match',
                        'exception' => null,
                    ],
                ],
                $this->json($response),
                $path,
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesTheRouteRefuses(): iterable
    {
        yield 'one of a single character' => ['a'];

        yield 'one longer than sixty-four characters' => [
            'announcements0123456789012345678901234567890123456789012345678901234',
        ];
    }

    public function testANameThatDiffersOnlyInItsCasingResolvesToNothing(): void
    {
        $name = $this->aMailingListName();
        $shouted = strtoupper($name);
        $token = $this->principalWith([
            ApiPermissions::MailingListsR,
            ApiPermissions::MailingListMembersR,
        ]);

        self::assertNotSame(
            $name,
            $shouted,
        );

        foreach (
            [
                '/api/mailing-lists/' . $shouted,
                '/api/mailing-lists/' . $shouted . '/members',
            ] as $path
        ) {
            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $this->get(
                    $path,
                    $token,
                )->getStatusCode(),
                $path,
            );
        }

        self::assertSame(
            Response::HTTP_OK,
            $this->get(
                '/api/mailing-lists/' . $name,
                $token,
            )->getStatusCode(),
        );
    }

    public function testASubscriberRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $response = $this->get(
            '/api/mailing-lists/' . $this->aMailingListWithSeveralSubscribers() . '/members',
            $this->principalWith([ApiPermissions::MailingListMembersR]),
            ['itemsPerPage' => 1],
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertSame(
            self::SUBSCRIBER_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testTheSubscribersArePaged(): void
    {
        $token = $this->principalWith([ApiPermissions::MailingListMembersR]);
        $path = '/api/mailing-lists/' . $this->aMailingListWithSeveralSubscribers() . '/members';

        $first = $this->json($this->get(
            $path,
            $token,
            ['itemsPerPage' => 1],
        ));
        $second = $this->json($this->get(
            $path,
            $token,
            [
                'itemsPerPage' => 1,
                'page' => 2,
            ],
        ));

        self::assertSame(
            2,
            $second['meta']['page'],
        );
        self::assertSame(
            $first['meta']['totalItems'],
            $second['meta']['totalItems'],
        );
        self::assertNotSame(
            $first['data'][0],
            $second['data'][0],
        );
    }

    public function testAListNobodySubscribesToIsAnEmptyPageRatherThanAMissingResource(): void
    {
        $response = $this->get(
            '/api/mailing-lists/' . $this->aMailingListWithoutSubscribers() . '/members',
            $this->principalWith([ApiPermissions::MailingListMembersR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            [],
            $body['data'],
        );
        self::assertSame(
            0,
            $body['meta']['totalItems'],
        );
    }

    public function testADeletedMemberIsNoSubscriberUntilThePrincipalMaySeeDeletedMembers(): void
    {
        $name = $this->aMailingListWithSeveralSubscribers();
        $lidnr = $this->aSubscriberLidnr($name);
        $this->deleteMember($lidnr);

        $path = '/api/mailing-lists/' . $name . '/members';

        $withheld = $this->json($this->get(
            $path,
            $this->principalWith([ApiPermissions::MailingListMembersR]),
            ['itemsPerPage' => 500],
        ));
        self::assertNotContains(
            $lidnr,
            array_column(
                $withheld['data'],
                'lidnr',
            ),
        );

        $allowed = $this->json($this->get(
            $path,
            $this->principalWith([
                ApiPermissions::MailingListMembersR,
                ApiPermissions::MembersDeleted,
            ]),
            ['itemsPerPage' => 500],
        ));
        self::assertContains(
            $lidnr,
            array_column(
                $allowed['data'],
                'lidnr',
            ),
        );
        self::assertGreaterThan(
            $withheld['meta']['totalItems'],
            $allowed['meta']['totalItems'],
        );
    }

    private function resolve(string $template): string
    {
        return strtr(
            $template,
            ['{name}' => $this->aMailingListName()],
        );
    }

    private function aMailingListName(): string
    {
        return (string) $this->scalar(
            'SELECT ml.name FROM ' . ProjectedMailingList::class . ' ml ORDER BY ml.name ASC',
        );
    }

    private function aMailingListWithSeveralSubscribers(): string
    {
        return (string) $this->scalar(
            'SELECT ml.name FROM ' . ProjectedMailingListMember::class . ' mlm'
            . ' JOIN mlm.mailingList ml JOIN mlm.member m WHERE m.deleted = false'
            . ' GROUP BY ml.name HAVING COUNT(mlm.email) > 1 ORDER BY ml.name ASC',
        );
    }

    private function aMailingListWithoutSubscribers(): string
    {
        return (string) $this->scalar(
            'SELECT ml.name FROM ' . ProjectedMailingList::class . ' ml'
            . ' LEFT JOIN ml.mailingListMemberships mlm'
            . ' GROUP BY ml.name HAVING COUNT(mlm.email) = 0 ORDER BY ml.name ASC',
        );
    }

    private function aSubscriberLidnr(string $name): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . ProjectedMailingListMember::class . ' mlm'
            . ' JOIN mlm.mailingList ml JOIN mlm.member m'
            . ' WHERE ml.name = :name AND m.deleted = false ORDER BY m.lidnr ASC',
            ['name' => $name],
        );
    }

    private function deleteMember(int $lidnr): void
    {
        $member = $this->entityManager
            ->createQuery('SELECT m FROM ' . Member::class . ' m WHERE m.lidnr = :lidnr')
            ->setParameter(
                'lidnr',
                $lidnr,
            )
            ->getSingleResult();
        self::assertInstanceOf(
            Member::class,
            $member,
        );

        $member->setDeleted(true);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function scalar(
        string $dql,
        array $parameters = [],
    ): mixed {
        return $this->entityManager->createQuery($dql)
            ->setParameters($parameters)
            ->setMaxResults(1)
            ->getSingleScalarResult();
    }
}
