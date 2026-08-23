<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\ApiResource\Decision\BoardMember;
use App\Entity\Decision\BoardMember as ProjectedBoardMember;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Decision\BoardMemberProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_keys;
use function rsort;

#[CoversClass(BoardMember::class)]
#[CoversClass(BoardMemberProvider::class)]
final class BoardApiTest extends ApiTestCase
{
    private const array BOARD_MEMBER_KEYS = [
        'lidnr',
        'full_name',
        'function',
        'installDate',
        'releaseDate',
        'dischargeDate',
        'current',
    ];

    private const array META_KEYS = [
        'page',
        'itemsPerPage',
        'totalItems',
        'totalPages',
    ];

    private const string SITTING = 'bm.installDate <= CURRENT_TIMESTAMP()'
        . ' AND (bm.releaseDate IS NULL OR bm.releaseDate > CURRENT_TIMESTAMP())'
        . ' AND (bm.dischargeDate IS NULL OR bm.dischargeDate > CURRENT_TIMESTAMP())';

    private const string SITTING_SUBQUERY = 'bm2.installDate <= CURRENT_TIMESTAMP()'
        . ' AND (bm2.releaseDate IS NULL OR bm2.releaseDate > CURRENT_TIMESTAMP())'
        . ' AND (bm2.dischargeDate IS NULL OR bm2.dischargeDate > CURRENT_TIMESTAMP())';

    public function testTheBoardsAreClosedToAPrincipalThatDoesNotHoldThePermission(): void
    {
        $response = $this->get(
            '/api/boards',
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
                    'exception' => 'Permission `' . ApiPermissions::BoardsR->value
                        . '` is needed but is not currently held.',
                ],
            ],
            $this->json($response),
        );
    }

    public function testTheBoardsAnswerNothingAtAllWithoutAToken(): void
    {
        $response = $this->get('/api/boards');

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );
        self::assertSame(
            '',
            (string) $response->getContent(),
        );
    }

    public function testTheBoardsExpectTheVersionedContract(): void
    {
        $response = $this->get(
            '/api/boards',
            $this->principalWith([ApiPermissions::BoardsR]),
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

    public function testABoardInstallationRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $response = $this->get(
            '/api/boards',
            $this->principalWith([ApiPermissions::BoardsR]),
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
            self::BOARD_MEMBER_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testTheListPagesThroughTheInstallations(): void
    {
        $token = $this->principalWith([ApiPermissions::BoardsR]);

        $first = $this->json($this->get(
            '/api/boards',
            $token,
            ['itemsPerPage' => 1],
        ));
        $second = $this->json($this->get(
            '/api/boards',
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
            $first['data'][0],
            $second['data'][0],
        );
    }

    public function testAPageOutOfRangeIsClampedRatherThanRefused(): void
    {
        $token = $this->principalWith([ApiPermissions::BoardsR]);

        $tooLarge = $this->json($this->get(
            '/api/boards',
            $token,
            ['itemsPerPage' => 100000],
        ));
        self::assertSame(
            500,
            $tooLarge['meta']['itemsPerPage'],
        );

        $tooSmall = $this->json($this->get(
            '/api/boards',
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
            '/api/boards',
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

    public function testOnlyTheSittingBoardIsListedUnlessTheFormerOnesAreAskedFor(): void
    {
        $token = $this->principalWith([ApiPermissions::BoardsR]);
        $former = $this->aFormerBoardMemberLidnr();

        $sitting = $this->json($this->get(
            '/api/boards',
            $token,
            ['itemsPerPage' => 500],
        ));
        self::assertNotContains(
            false,
            array_column(
                $sitting['data'],
                'current',
            ),
        );
        self::assertNotContains(
            $former,
            array_column(
                $sitting['data'],
                'lidnr',
            ),
        );

        $everything = $this->json($this->get(
            '/api/boards',
            $token,
            [
                'itemsPerPage' => 500,
                'includeFormer' => 'true',
            ],
        ));
        self::assertContains(
            $former,
            array_column(
                $everything['data'],
                'lidnr',
            ),
        );
        self::assertContains(
            false,
            array_column(
                $everything['data'],
                'current',
            ),
        );
        self::assertGreaterThan(
            $sitting['meta']['totalItems'],
            $everything['meta']['totalItems'],
        );
    }

    public function testTheInstallationsAreListedNewestFirst(): void
    {
        $body = $this->json($this->get(
            '/api/boards',
            $this->principalWith([ApiPermissions::BoardsR]),
            [
                'itemsPerPage' => 500,
                'includeFormer' => 'true',
            ],
        ));

        $dates = array_column(
            $body['data'],
            'installDate',
        );
        $sorted = $dates;
        rsort($sorted);

        self::assertSame(
            $sorted,
            $dates,
        );
    }

    public function testAnIncludeFormerThatCannotBeReadAsABooleanIsFalse(): void
    {
        $token = $this->principalWith([ApiPermissions::BoardsR]);

        $default = $this->json($this->get(
            '/api/boards',
            $token,
            ['itemsPerPage' => 500],
        ));
        $unparseable = $this->json($this->get(
            '/api/boards',
            $token,
            [
                'itemsPerPage' => 500,
                'includeFormer' => 'perhaps',
            ],
        ));

        self::assertSame(
            $default['meta']['totalItems'],
            $unparseable['meta']['totalItems'],
        );
        self::assertSame(
            $default['data'],
            $unparseable['data'],
        );
    }

    public function testAnInstallationIsNotAddressableOnItsOwn(): void
    {
        $response = $this->get(
            '/api/boards/' . $this->aSittingBoardMemberLidnr(),
            $this->principalWith([ApiPermissions::BoardsR]),
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

    public function testADeletedMemberIsNoBoardMemberUntilThePrincipalMaySeeDeletedMembers(): void
    {
        $lidnr = $this->aSittingBoardMemberLidnr();
        $this->deleteMember($lidnr);

        $withheld = $this->json($this->get(
            '/api/boards',
            $this->principalWith([ApiPermissions::BoardsR]),
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
            '/api/boards',
            $this->principalWith([
                ApiPermissions::BoardsR,
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

    private function aSittingBoardMemberLidnr(): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . ProjectedBoardMember::class . ' bm JOIN bm.member m'
            . ' WHERE m.deleted = false AND ' . self::SITTING . ' ORDER BY bm.id ASC',
        );
    }

    private function aFormerBoardMemberLidnr(): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . ProjectedBoardMember::class . ' bm JOIN bm.member m'
            . ' WHERE m.deleted = false AND m.lidnr NOT IN ('
            . 'SELECT m2.lidnr FROM ' . ProjectedBoardMember::class . ' bm2 JOIN bm2.member m2'
            . ' WHERE ' . self::SITTING_SUBQUERY . ') ORDER BY bm.id ASC',
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

    private function scalar(string $dql): mixed
    {
        return $this->entityManager->createQuery($dql)
            ->setMaxResults(1)
            ->getSingleScalarResult();
    }
}
