<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\ApiResource\Decision\Keyholder;
use App\Entity\Decision\Keyholder as ProjectedKeyholder;
use App\Entity\Decision\Member;
use App\Entity\User\Enums\ApiPermissions;
use App\State\Decision\KeyholderProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_keys;

#[CoversClass(Keyholder::class)]
#[CoversClass(KeyholderProvider::class)]
final class KeyholderApiTest extends ApiTestCase
{
    private const array KEYHOLDER_KEYS = [
        'lidnr',
        'full_name',
        'expirationDate',
        'withdrawnDate',
        'current',
    ];

    private const array META_KEYS = [
        'page',
        'itemsPerPage',
        'totalItems',
        'totalPages',
    ];

    public function testTheKeyholdersAreClosedToAPrincipalThatDoesNotHoldThePermission(): void
    {
        $response = $this->get(
            '/api/keyholders',
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
                    'exception' => 'Permission `' . ApiPermissions::KeyholdersR->value
                        . '` is needed but is not currently held.',
                ],
            ],
            $this->json($response),
        );
    }

    public function testTheKeyholdersAnswerNothingAtAllWithoutAToken(): void
    {
        $response = $this->get('/api/keyholders');

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );
        self::assertSame(
            '',
            (string) $response->getContent(),
        );
    }

    public function testTheKeyholdersExpectTheVersionedContract(): void
    {
        $response = $this->get(
            '/api/keyholders',
            $this->principalWith([ApiPermissions::KeyholdersR]),
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

    public function testAKeyholderRowNamesEveryFieldInTheOrderItPromises(): void
    {
        $response = $this->get(
            '/api/keyholders',
            $this->principalWith([ApiPermissions::KeyholdersR]),
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
            self::KEYHOLDER_KEYS,
            array_keys($body['data'][0]),
        );
        self::assertSame(
            self::META_KEYS,
            array_keys($body['meta']),
        );
    }

    public function testTheListPagesThroughTheKeyholders(): void
    {
        $token = $this->principalWith([ApiPermissions::KeyholdersR]);

        $first = $this->json($this->get(
            '/api/keyholders',
            $token,
            [
                'itemsPerPage' => 1,
                'includeExpired' => 'true',
            ],
        ));
        $second = $this->json($this->get(
            '/api/keyholders',
            $token,
            [
                'itemsPerPage' => 1,
                'includeExpired' => 'true',
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
        $token = $this->principalWith([ApiPermissions::KeyholdersR]);

        $tooLarge = $this->json($this->get(
            '/api/keyholders',
            $token,
            ['itemsPerPage' => 100000],
        ));
        self::assertSame(
            500,
            $tooLarge['meta']['itemsPerPage'],
        );

        $tooSmall = $this->json($this->get(
            '/api/keyholders',
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
            '/api/keyholders',
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

    public function testOnlyTheGrantingsInForceAreListedUnlessTheExpiredOnesAreAskedFor(): void
    {
        $token = $this->principalWith([ApiPermissions::KeyholdersR]);
        $expired = $this->anExpiredKeyholderLidnr();

        $current = $this->json($this->get(
            '/api/keyholders',
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
        self::assertNotContains(
            $expired,
            array_column(
                $current['data'],
                'lidnr',
            ),
        );

        $everything = $this->json($this->get(
            '/api/keyholders',
            $token,
            [
                'itemsPerPage' => 500,
                'includeExpired' => 'true',
            ],
        ));
        self::assertContains(
            $expired,
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
            $current['meta']['totalItems'],
            $everything['meta']['totalItems'],
        );
    }

    public function testAnIncludeExpiredThatCannotBeReadAsABooleanIsFalse(): void
    {
        $token = $this->principalWith([ApiPermissions::KeyholdersR]);

        $default = $this->json($this->get(
            '/api/keyholders',
            $token,
            ['itemsPerPage' => 500],
        ));
        $unparseable = $this->json($this->get(
            '/api/keyholders',
            $token,
            [
                'itemsPerPage' => 500,
                'includeExpired' => 'perhaps',
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

    public function testAGrantingIsNotAddressableOnItsOwn(): void
    {
        $response = $this->get(
            '/api/keyholders/' . $this->aCurrentKeyholderLidnr(),
            $this->principalWith([ApiPermissions::KeyholdersR]),
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

    public function testADeletedMemberIsNoKeyholderUntilThePrincipalMaySeeDeletedMembers(): void
    {
        $lidnr = $this->aCurrentKeyholderLidnr();
        $this->deleteMember($lidnr);

        $withheld = $this->json($this->get(
            '/api/keyholders',
            $this->principalWith([ApiPermissions::KeyholdersR]),
            [
                'itemsPerPage' => 500,
                'includeExpired' => 'true',
            ],
        ));
        self::assertNotContains(
            $lidnr,
            array_column(
                $withheld['data'],
                'lidnr',
            ),
        );

        $allowed = $this->json($this->get(
            '/api/keyholders',
            $this->principalWith([
                ApiPermissions::KeyholdersR,
                ApiPermissions::MembersDeleted,
            ]),
            [
                'itemsPerPage' => 500,
                'includeExpired' => 'true',
            ],
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

    private function aCurrentKeyholderLidnr(): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . ProjectedKeyholder::class . ' k JOIN k.member m'
            . ' WHERE m.deleted = false AND k.expirationDate >= CURRENT_DATE()'
            . ' AND (k.withdrawnDate IS NULL OR k.withdrawnDate >= CURRENT_DATE())'
            . ' ORDER BY k.id ASC',
        );
    }

    private function anExpiredKeyholderLidnr(): int
    {
        return (int) $this->scalar(
            'SELECT m.lidnr FROM ' . ProjectedKeyholder::class . ' k JOIN k.member m'
            . ' WHERE m.deleted = false'
            . ' AND (k.expirationDate < CURRENT_DATE() OR k.withdrawnDate < CURRENT_DATE())'
            . ' ORDER BY k.id ASC',
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
