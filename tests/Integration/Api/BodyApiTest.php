<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\ApiResource\Decision\Body;
use App\ApiResource\Decision\BodyMember;
use App\ApiResource\Decision\BodySummary;
use App\ApiResource\Decision\MemberBody;
use App\Entity\Decision\Member;
use App\Entity\Decision\Organ;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Decision\BodyMemberProvider;
use App\State\Decision\BodyProvider;
use App\State\Decision\MemberBodyProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_keys;
use function count;
use function strtr;

#[CoversClass(Body::class)]
#[CoversClass(BodyMember::class)]
#[CoversClass(BodySummary::class)]
#[CoversClass(MemberBody::class)]
#[CoversClass(BodyProvider::class)]
#[CoversClass(BodyMemberProvider::class)]
#[CoversClass(MemberBodyProvider::class)]
final class BodyApiTest extends ApiTestCase
{
    private const array BODY_KEYS = [
        'id',
        'abbreviation',
        'name',
        'type',
        'foundationDate',
        'abrogationDate',
        'active',
    ];

    private const array INSTALLATION_KEYS = [
        'lidnr',
        'full_name',
        'function',
        'installDate',
        'dischargeDate',
        'current',
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

    /**
     * @return iterable<string, array{string, ApiPermissions}>
     */
    public static function gatedOperations(): iterable
    {
        yield 'the bodies' => [
            '/api/bodies',
            ApiPermissions::BodiesR,
        ];

        yield 'one body' => [
            '/api/bodies/{body}',
            ApiPermissions::BodiesR,
        ];

        yield 'the members of a body' => [
            '/api/bodies/{body}/members',
            ApiPermissions::BodyMembersR,
        ];

        yield 'the bodies of a member' => [
            '/api/members/{member}/bodies',
            ApiPermissions::BodyMembersR,
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

    public function testABodyRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $body = $this->json($this->get(
            '/api/bodies',
            $this->principalWith([ApiPermissions::BodiesR]),
            ['itemsPerPage' => 1],
        ));

        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertSame(
            self::BODY_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testASingleBodyIsTheSameRowAndCarriesNoPage(): void
    {
        $response = $this->get(
            '/api/bodies/' . $this->anyBodyId(),
            $this->principalWith([ApiPermissions::BodiesR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            self::BODY_KEYS,
            array_keys($body['data']),
        );
        self::assertArrayNotHasKey(
            'meta',
            $body,
        );
    }

    public function testTheListPagesThroughTheBodies(): void
    {
        $token = $this->principalWith([ApiPermissions::BodiesR]);

        $first = $this->json($this->get(
            '/api/bodies',
            $token,
            ['itemsPerPage' => 1],
        ));
        $second = $this->json($this->get(
            '/api/bodies',
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
        $token = $this->principalWith([ApiPermissions::BodiesR]);

        $tooLarge = $this->json($this->get(
            '/api/bodies',
            $token,
            ['itemsPerPage' => 100000],
        ));
        self::assertSame(
            500,
            $tooLarge['meta']['itemsPerPage'],
        );

        $tooSmall = $this->json($this->get(
            '/api/bodies',
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

    public function testOnlyTheBodiesThatStillExistAreListedUnlessTheAbrogatedOnesAreAskedFor(): void
    {
        $token = $this->principalWith([ApiPermissions::BodiesR]);
        $abrogated = $this->anAbrogatedBodyId();

        $current = $this->json($this->get(
            '/api/bodies',
            $token,
            ['itemsPerPage' => 500],
        ));
        self::assertNotContains(
            false,
            array_column(
                $current['data'],
                'active',
            ),
        );
        self::assertNotContains(
            $abrogated,
            array_column(
                $current['data'],
                'id',
            ),
        );

        $all = $this->json($this->get(
            '/api/bodies',
            $token,
            [
                'itemsPerPage' => 500,
                'includeAbrogated' => 'true',
            ],
        ));
        self::assertContains(
            $abrogated,
            array_column(
                $all['data'],
                'id',
            ),
        );
    }

    public function testAnAbrogatedBodyIsStillAddressableByTheConsumerThatHoldsItsId(): void
    {
        $response = $this->get(
            '/api/bodies/' . $this->anAbrogatedBodyId(),
            $this->principalWith([ApiPermissions::BodiesR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertFalse($body['data']['active']);
        self::assertNotNull($body['data']['abrogationDate']);
    }

    public function testAnIncludeAbrogatedThatCannotBeReadAsABooleanIsFalse(): void
    {
        $token = $this->principalWith([ApiPermissions::BodiesR]);

        $default = $this->json($this->get(
            '/api/bodies',
            $token,
            ['itemsPerPage' => 1],
        ));
        $unparseable = $this->json($this->get(
            '/api/bodies',
            $token,
            [
                'itemsPerPage' => 1,
                'includeAbrogated' => 'maybe',
            ],
        ));

        self::assertSame(
            $default['meta']['totalItems'],
            $unparseable['meta']['totalItems'],
        );
    }

    public function testTheTypeParameterNarrowsToOneKindOfBodyAndIsIgnoredWhenItNamesNone(): void
    {
        $token = $this->principalWith([ApiPermissions::BodiesR]);

        $everything = $this->json($this->get(
            '/api/bodies',
            $token,
            ['itemsPerPage' => 500],
        ));
        $fraternities = $this->json($this->get(
            '/api/bodies',
            $token,
            [
                'itemsPerPage' => 500,
                'type' => 'fraternity',
            ],
        ));
        $nonsense = $this->json($this->get(
            '/api/bodies',
            $token,
            [
                'itemsPerPage' => 500,
                'type' => 'nonsense',
            ],
        ));

        self::assertNotEmpty($fraternities['data']);
        self::assertLessThan(
            $everything['meta']['totalItems'],
            $fraternities['meta']['totalItems'],
        );
        self::assertSame(
            ['fraternity'],
            array_keys($this->tally(array_column(
                $fraternities['data'],
                'type',
            ))),
        );
        self::assertSame(
            $everything['meta']['totalItems'],
            $nonsense['meta']['totalItems'],
        );
    }

    public function testAnUnknownBodyIsAMissingResource(): void
    {
        $response = $this->get(
            '/api/bodies/99999999',
            $this->principalWith([ApiPermissions::BodiesR]),
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

    public function testABodyIdOfTheWrongShapeReachesNoOperationAtAll(): void
    {
        $response = $this->get(
            '/api/bodies/eleven',
            $this->principalWith([ApiPermissions::BodiesR]),
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

    public function testTheCompositionOfABodyNamesTheMemberAndTheFunctionAndNothingElse(): void
    {
        $body = $this->json($this->get(
            '/api/bodies/' . $this->aBodyWithADischargedInstallation() . '/members',
            $this->principalWith([ApiPermissions::BodyMembersR]),
            ['itemsPerPage' => 1],
        ));

        self::assertSame(
            self::INSTALLATION_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testAnInstallationThatHasEndedIsWithheldUntilItIsAskedFor(): void
    {
        $token = $this->principalWith([ApiPermissions::BodyMembersR]);
        $path = '/api/bodies/' . $this->aBodyWithADischargedInstallation() . '/members';

        $current = $this->json($this->get(
            $path,
            $token,
            ['itemsPerPage' => 500],
        ));
        self::assertNotContains(
            false,
            array_column(
                $current['data'],
                'current',
            ),
        );

        $everyone = $this->json($this->get(
            $path,
            $token,
            [
                'itemsPerPage' => 500,
                'includeDischarged' => 'true',
            ],
        ));
        self::assertContains(
            false,
            array_column(
                $everyone['data'],
                'current',
            ),
        );
        self::assertGreaterThan(
            $current['meta']['totalItems'],
            $everyone['meta']['totalItems'],
        );
    }

    public function testABodyNobodySitsInIsAnEmptyPageAndOneThatDoesNotExistIsNot(): void
    {
        $token = $this->principalWith([ApiPermissions::BodyMembersR]);

        $empty = $this->json($this->get(
            '/api/bodies/' . $this->aBodyNobodyWasEverInstalledIn() . '/members',
            $token,
        ));
        self::assertSame(
            [],
            $empty['data'],
        );
        self::assertSame(
            0,
            $empty['meta']['totalItems'],
        );

        $missing = $this->get(
            '/api/bodies/99999999/members',
            $token,
        );
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $missing->getStatusCode(),
        );
    }

    public function testTheBodiesOfAMemberNameTheBodyRatherThanItsAbbreviationAlone(): void
    {
        $body = $this->json($this->get(
            '/api/members/' . $this->aMemberInSeveralBodies() . '/bodies',
            $this->principalWith([ApiPermissions::BodyMembersR]),
            ['itemsPerPage' => 500],
        ));

        self::assertSame(
            [
                'body',
                'function',
                'installDate',
                'dischargeDate',
                'current',
            ],
            array_keys($body['data'][0]),
        );
        self::assertSame(
            [
                'id',
                'abbreviation',
                'name',
                'type',
            ],
            array_keys($body['data'][0]['body']),
        );
        self::assertGreaterThan(
            1,
            count($this->tally(array_column(
                array_column(
                    $body['data'],
                    'body',
                ),
                'id',
            ))),
            'the member this reads was picked for sitting in more than one body',
        );
    }

    public function testTheBodiesOfAMemberArePaged(): void
    {
        $token = $this->principalWith([ApiPermissions::BodyMembersR]);
        $path = '/api/members/' . $this->aMemberInSeveralBodies() . '/bodies';

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

        self::assertNotSame(
            $first['data'][0],
            $second['data'][0],
        );
        self::assertSame(
            2,
            $second['meta']['page'],
        );
    }

    public function testAMemberWhoSitsInNothingIsAnEmptyPageAndAnUnknownOneIsAMissingResource(): void
    {
        $token = $this->principalWith([ApiPermissions::BodyMembersR]);

        $empty = $this->json($this->get(
            '/api/members/' . $this->aMemberInNoBody() . '/bodies',
            $token,
        ));
        self::assertSame(
            [],
            $empty['data'],
        );

        $missing = $this->get(
            '/api/members/99999999/bodies',
            $token,
        );
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $missing->getStatusCode(),
        );
        self::assertSame(
            'error-resource-not-found',
            $this->json($missing)['error']['type'],
        );
    }

    public function testADeletedMemberIsNotThereAtAllUntilThePrincipalMaySeeDeletedMembers(): void
    {
        $lidnr = $this->aDeletedMemberInABody();

        $withheld = $this->get(
            '/api/members/' . $lidnr . '/bodies',
            $this->principalWith([ApiPermissions::BodyMembersR]),
        );
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $withheld->getStatusCode(),
        );

        $allowed = $this->get(
            '/api/members/' . $lidnr . '/bodies',
            $this->principalWith([
                ApiPermissions::BodyMembersR,
                ApiPermissions::MembersDeleted,
            ]),
            [
                'itemsPerPage' => 500,
                'includeDischarged' => 'true',
            ],
        );
        self::assertSame(
            Response::HTTP_OK,
            $allowed->getStatusCode(),
        );
        self::assertNotEmpty($this->json($allowed)['data']);
    }

    private function resolve(string $template): string
    {
        return strtr(
            $template,
            [
                '{body}' => (string) $this->anyBodyId(),
                '{member}' => (string) $this->aMemberInSeveralBodies(),
            ],
        );
    }

    private function anyBodyId(): int
    {
        return (int) $this->scalar('SELECT o.id FROM ' . Organ::class . ' o ORDER BY o.id ASC');
    }

    private function anAbrogatedBodyId(): int
    {
        return (int) $this->scalar(
            'SELECT o.id FROM ' . Organ::class . ' o'
            . ' WHERE o.abrogationDate < CURRENT_TIMESTAMP() ORDER BY o.id ASC',
        );
    }

    private function aBodyWithADischargedInstallation(): int
    {
        return (int) $this->scalar(
            'SELECT o.id FROM ' . Organ::class . ' o JOIN o.members om'
            . ' WHERE om.dischargeDate < CURRENT_TIMESTAMP()'
            . ' AND (o.abrogationDate IS NULL OR o.abrogationDate > CURRENT_TIMESTAMP())'
            . ' GROUP BY o.id HAVING COUNT(om.id) > 1 ORDER BY o.id ASC',
        );
    }

    private function aBodyNobodyWasEverInstalledIn(): int
    {
        return (int) $this->scalar(
            'SELECT o.id FROM ' . Organ::class . ' o LEFT JOIN o.members om'
            . ' GROUP BY o.id HAVING COUNT(om.id) = 0 ORDER BY o.id ASC',
        );
    }

    private function aMemberInSeveralBodies(): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . Organ::class . ' o JOIN o.members om JOIN om.member m'
            . ' WHERE m.deleted = false GROUP BY m.lidnr HAVING COUNT(DISTINCT o.id) > 1 ORDER BY m.lidnr ASC',
        );
    }

    private function aMemberInNoBody(): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . Member::class . ' m LEFT JOIN m.organInstallations om'
            . ' WHERE m.deleted = false GROUP BY m.lidnr HAVING COUNT(om.id) = 0 ORDER BY m.lidnr ASC',
        );
    }

    private function aDeletedMemberInABody(): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . Member::class . ' m JOIN m.organInstallations om'
            . ' WHERE m.deleted = true GROUP BY m.lidnr ORDER BY m.lidnr ASC',
        );
    }

    /**
     * @param list<string|int> $values
     *
     * @return array<string|int, int>
     */
    private function tally(array $values): array
    {
        $tally = [];

        foreach ($values as $value) {
            $tally[$value] = ($tally[$value] ?? 0) + 1;
        }

        return $tally;
    }

    private function scalar(string $dql): mixed
    {
        return $this->entityManager->createQuery($dql)
            ->setMaxResults(1)
            ->getSingleScalarResult();
    }
}
