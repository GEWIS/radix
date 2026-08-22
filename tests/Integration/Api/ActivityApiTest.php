<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\ApiResource\Activity\Activity as ActivityResource;
use App\Entity\Activity\Activity as ActivityEntity;
use App\Entity\Decision\Organ;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Activity\ActivityProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_intersect;
use function array_keys;
use function array_merge;
use function array_unique;
use function count;
use function strtotime;
use function strtr;
use function time;

#[CoversClass(ActivityResource::class)]
#[CoversClass(ActivityProvider::class)]
final class ActivityApiTest extends ApiTestCase
{
    private const array ACTIVITY_KEYS = [
        'id',
        'name',
        'description',
        'location',
        'costs',
        'beginTime',
        'endTime',
        'category',
        'organ',
        'company',
        'requireGEFLITST',
        'requireZettle',
        'cancelled',
        'labels',
        'signupLists',
    ];

    private const array TEXT_KEYS = [
        'en',
        'nl',
    ];

    private const array META_KEYS = [
        'page',
        'itemsPerPage',
        'totalItems',
        'totalPages',
    ];

    #[DataProvider('gatedPaths')]
    public function testAnOperationIsClosedToAPrincipalThatDoesNotHoldItsPermission(string $path): void
    {
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
                    'exception' => 'Permission `' . ApiPermissions::ActivitiesR->value
                        . '` is needed but is not currently held.',
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

    #[DataProvider('gatedPaths')]
    public function testAnOperationRefusesAConsumerThatStatesNoContractVersion(string $path): void
    {
        $response = $this->get(
            $this->resolve($path),
            $this->principalWith([ApiPermissions::ActivitiesR]),
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
     * @return iterable<string, array{string}>
     */
    public static function gatedPaths(): iterable
    {
        yield 'the activities' => ['/api/activities'];

        yield 'one activity' => ['/api/activities/{activity}'];
    }

    public function testAnActivityRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $body = $this->json($this->get(
            '/api/activities',
            $this->principalWith([ApiPermissions::ActivitiesR]),
            ['itemsPerPage' => 1],
        ));

        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertSame(
            self::ACTIVITY_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testEveryHumanReadableFieldIsAPairOfLanguages(): void
    {
        $body = $this->json($this->get(
            '/api/activities',
            $this->principalWith([ApiPermissions::ActivitiesR]),
            ['itemsPerPage' => 1],
        ));

        foreach (
            [
                'name',
                'description',
                'location',
                'costs',
            ] as $field
        ) {
            self::assertSame(
                self::TEXT_KEYS,
                array_keys($body['data'][0][$field]),
                $field . ' is stated per language',
            );
        }
    }

    public function testASingleActivityIsTheSameRowAndCarriesNoPage(): void
    {
        $response = $this->get(
            '/api/activities/' . $this->aPublicActivityId(),
            $this->principalWith([ApiPermissions::ActivitiesR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            self::ACTIVITY_KEYS,
            array_keys($body['data']),
        );
        self::assertArrayNotHasKey(
            'meta',
            $body,
        );
    }

    public function testTheListPagesThroughTheActivities(): void
    {
        $token = $this->principalWith([ApiPermissions::ActivitiesR]);

        $first = $this->json($this->get(
            '/api/activities',
            $token,
            ['itemsPerPage' => 1],
        ));
        $second = $this->json($this->get(
            '/api/activities',
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
            $first['data'][0]['id'],
            $second['data'][0]['id'],
        );
    }

    public function testAPageOutOfRangeIsClampedRatherThanRefused(): void
    {
        $token = $this->principalWith([ApiPermissions::ActivitiesR]);

        $tooLarge = $this->json($this->get(
            '/api/activities',
            $token,
            ['itemsPerPage' => 100000],
        ));
        self::assertSame(
            500,
            $tooLarge['meta']['itemsPerPage'],
        );

        $tooSmall = $this->json($this->get(
            '/api/activities',
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
    }

    public function testAPagePastTheEndIsEmptyRatherThanRefused(): void
    {
        $response = $this->get(
            '/api/activities',
            $this->principalWith([ApiPermissions::ActivitiesR]),
            ['page' => 99999],
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            [],
            $this->json($response)['data'],
        );
    }

    public function testThePastParameterSwapsTheWindowAndAnUnreadableOneAsksForWhatIsStillToCome(): void
    {
        $token = $this->principalWith([ApiPermissions::ActivitiesR]);

        $upcoming = $this->json($this->everything(
            $token,
            [],
        ));
        $past = $this->json($this->everything(
            $token,
            ['past' => 'true'],
        ));
        $unreadable = $this->json($this->everything(
            $token,
            ['past' => 'perhaps'],
        ));

        self::assertNotEmpty($upcoming['data']);
        self::assertNotEmpty($past['data']);

        $now = time();

        foreach ($upcoming['data'] as $row) {
            self::assertGreaterThan(
                $now,
                strtotime($row['endTime']),
            );
        }

        foreach ($past['data'] as $row) {
            self::assertLessThan(
                $now,
                strtotime($row['endTime']),
            );
        }

        self::assertSame(
            [],
            array_intersect(
                array_column(
                    $upcoming['data'],
                    'id',
                ),
                array_column(
                    $past['data'],
                    'id',
                ),
            ),
        );
        self::assertSame(
            $this->publicActivityCount(),
            $upcoming['meta']['totalItems'] + $past['meta']['totalItems'],
        );
        self::assertSame(
            $upcoming['meta']['totalItems'],
            $unreadable['meta']['totalItems'],
        );
    }

    public function testTheCategoryParameterNarrowsToOneCategoryAndIsIgnoredWhenItNamesNone(): void
    {
        $token = $this->principalWith([ApiPermissions::ActivitiesR]);

        $everything = $this->json($this->everything(
            $token,
            [],
        ));
        $categories = array_column(
            $everything['data'],
            'category',
        );
        self::assertGreaterThan(
            1,
            count(array_unique($categories)),
            'the upcoming activities are seeded across several categories, so one of them narrows the list',
        );

        $narrowed = $this->json($this->everything(
            $token,
            ['category' => $categories[0]],
        ));
        $nonsense = $this->json($this->everything(
            $token,
            ['category' => 'no-such-category'],
        ));

        self::assertNotEmpty($narrowed['data']);
        self::assertSame(
            [$categories[0]],
            array_unique(array_column(
                $narrowed['data'],
                'category',
            )),
        );
        self::assertLessThan(
            $everything['meta']['totalItems'],
            $narrowed['meta']['totalItems'],
        );
        self::assertSame(
            $everything['meta']['totalItems'],
            $nonsense['meta']['totalItems'],
        );
    }

    public function testTheOrganParameterNarrowsToTheOrganisingBodyAndIsIgnoredWhenItIsNotANumber(): void
    {
        $token = $this->principalWith([ApiPermissions::ActivitiesR]);
        $organ = $this->anyBodyId();

        $everything = $this->json($this->everything(
            $token,
            [],
        ));
        $narrowed = $this->json($this->everything(
            $token,
            ['organ' => $organ],
        ));
        $unreadable = $this->json($this->everything(
            $token,
            ['organ' => 'not-a-number'],
        ));

        foreach ($narrowed['data'] as $row) {
            self::assertSame(
                $organ,
                $row['organ']['id'],
            );
        }

        self::assertLessThan(
            $everything['meta']['totalItems'],
            $narrowed['meta']['totalItems'],
            'no publicly visible activity in the seed is organised by a body, so naming one empties the list',
        );
        self::assertSame(
            $everything['meta']['totalItems'],
            $unreadable['meta']['totalItems'],
        );
    }

    public function testAnUnknownActivityIsAMissingResource(): void
    {
        $response = $this->get(
            '/api/activities/99999999',
            $this->principalWith([ApiPermissions::ActivitiesR]),
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
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
        );
    }

    public function testAnActivityIdOfTheWrongShapeReachesNoOperationAtAll(): void
    {
        $response = $this->get(
            '/api/activities/eleven',
            $this->principalWith([ApiPermissions::ActivitiesR]),
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
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
        );
    }

    public function testAnActivityThatWasNeverApprovedIsNeitherListedNorAddressable(): void
    {
        $this->assertNotPublic($this->anActivityWithoutALiveRevision());
    }

    public function testAnActivityTheBoardUnpublishedIsNeitherListedNorAddressable(): void
    {
        $this->assertNotPublic($this->anUnpublishedActivityId());
    }

    public function testACancelledActivityIsStillListedAndSaysSoOnItsOwnUrl(): void
    {
        $token = $this->principalWith([ApiPermissions::ActivitiesR]);
        $cancelled = $this->aCancelledActivityId();

        self::assertContains(
            $cancelled,
            $this->listedActivityIds($token),
        );

        $response = $this->get(
            '/api/activities/' . $cancelled,
            $token,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertTrue($this->json($response)['data']['cancelled']);
    }

    private function assertNotPublic(int $id): void
    {
        $token = $this->principalWith([ApiPermissions::ActivitiesR]);

        self::assertNotContains(
            $id,
            $this->listedActivityIds($token),
        );

        $response = $this->get(
            '/api/activities/' . $id,
            $token,
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            'error-resource-not-found',
            $this->json($response)['error']['type'],
        );
    }

    /**
     * @return list<int>
     */
    private function listedActivityIds(string $token): array
    {
        $upcoming = $this->json($this->everything(
            $token,
            [],
        ));
        $past = $this->json($this->everything(
            $token,
            ['past' => 'true'],
        ));

        return array_merge(
            array_column(
                $upcoming['data'],
                'id',
            ),
            array_column(
                $past['data'],
                'id',
            ),
        );
    }

    /**
     * @param array<string, scalar> $query
     */
    private function everything(
        string $token,
        array $query,
    ): Response {
        return $this->get(
            '/api/activities',
            $token,
            array_merge(
                ['itemsPerPage' => 500],
                $query,
            ),
        );
    }

    private function resolve(string $template): string
    {
        return strtr(
            $template,
            ['{activity}' => (string) $this->aPublicActivityId()],
        );
    }

    private function aPublicActivityId(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . ActivityEntity::class . ' a'
            . ' WHERE a.liveRevision IS NOT NULL AND a.unpublishedAt IS NULL ORDER BY a.id ASC',
        );
    }

    private function anActivityWithoutALiveRevision(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . ActivityEntity::class . ' a'
            . ' WHERE a.liveRevision IS NULL ORDER BY a.id ASC',
        );
    }

    private function anUnpublishedActivityId(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . ActivityEntity::class . ' a'
            . ' WHERE a.liveRevision IS NOT NULL AND a.unpublishedAt IS NOT NULL ORDER BY a.id ASC',
        );
    }

    private function aCancelledActivityId(): int
    {
        return (int) $this->scalar(
            'SELECT a.id FROM ' . ActivityEntity::class . ' a'
            . ' WHERE a.liveRevision IS NOT NULL AND a.unpublishedAt IS NULL AND a.cancelledAt IS NOT NULL'
            . ' ORDER BY a.id ASC',
        );
    }

    private function publicActivityCount(): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(a.id) FROM ' . ActivityEntity::class . ' a'
            . ' WHERE a.liveRevision IS NOT NULL AND a.unpublishedAt IS NULL',
        );
    }

    private function anyBodyId(): int
    {
        return (int) $this->scalar('SELECT o.id FROM ' . Organ::class . ' o ORDER BY o.id ASC');
    }

    private function scalar(string $dql): mixed
    {
        return $this->entityManager->createQuery($dql)
            ->setMaxResults(1)
            ->getSingleScalarResult();
    }
}
