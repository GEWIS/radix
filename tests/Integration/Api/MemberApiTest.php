<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\ApiResource\Decision\Member;
use App\Entity\User\Enums\ApiPermissions;
use App\Serializer\Api\MemberSerializationGroups;
use App\State\Decision\MemberProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

use function array_keys;
use function array_merge;

#[CoversClass(Member::class)]
#[CoversClass(MemberProvider::class)]
#[CoversClass(MemberSerializationGroups::class)]
final class MemberApiTest extends ApiTestCase
{
    private const array ALWAYS = [
        'lidnr',
        'full_name',
        'family_name',
        'middle_name',
        'initials',
        'given_name',
        'generation',
        'hidden',
        'deleted',
        'expiration',
    ];

    /**
     * @param ApiPermissions[] $extraPermissions
     * @param string[]         $extraKeys
     */
    #[DataProvider('propertyPermissions')]
    public function testAPropertyPermissionAddsExactlyItsOwnKey(
        array $extraPermissions,
        array $extraKeys,
    ): void {
        $response = $this->get(
            '/api/members',
            $this->principalWith(array_merge(
                [ApiPermissions::MembersR],
                $extraPermissions,
            )),
            ['itemsPerPage' => 1],
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertNotEmpty($body['data']);

        self::assertSame(
            array_merge(
                self::ALWAYS,
                $extraKeys,
            ),
            array_keys($body['data'][0]),
        );
    }

    /**
     * @return iterable<string, array{ApiPermissions[], string[]}>
     */
    public static function propertyPermissions(): iterable
    {
        yield 'nothing beyond members_read' => [
            [],
            [],
        ];

        yield 'email' => [
            [ApiPermissions::MembersPropertyEmail],
            ['email'],
        ];

        yield 'birthdate' => [
            [ApiPermissions::MembersPropertyBirthDate],
            ['birthdate'],
        ];

        yield 'age 16' => [
            [ApiPermissions::MembersPropertyAge16],
            ['is_16_plus'],
        ];

        yield 'age 18' => [
            [ApiPermissions::MembersPropertyAge18],
            ['is_18_plus'],
        ];

        yield 'age 21' => [
            [ApiPermissions::MembersPropertyAge21],
            ['is_21_plus'],
        ];

        yield 'keyholder' => [
            [ApiPermissions::MembersPropertyKeyholder],
            ['keyholder'],
        ];

        yield 'membership type' => [
            [ApiPermissions::MembersPropertyType],
            ['membership_type'],
        ];

        yield 'every property at once, in the emission order' => [
            [
                ApiPermissions::MembersPropertyEmail,
                ApiPermissions::MembersPropertyBirthDate,
                ApiPermissions::MembersPropertyAge16,
                ApiPermissions::MembersPropertyAge18,
                ApiPermissions::MembersPropertyAge21,
                ApiPermissions::MembersPropertyKeyholder,
                ApiPermissions::MembersPropertyType,
            ],
            [
                'email',
                'birthdate',
                'is_16_plus',
                'is_18_plus',
                'is_21_plus',
                'keyholder',
                'membership_type',
            ],
        ];
    }

    public function testBodyMembershipIsWithheldFromTheListUnlessItIsAskedFor(): void
    {
        $token = $this->principalWith([
            ApiPermissions::MembersR,
            ApiPermissions::OrgansMembershipR,
        ]);

        $withoutOrgans = $this->json($this->get(
            '/api/members',
            $token,
            ['itemsPerPage' => 1],
        ));
        self::assertArrayNotHasKey(
            'organs',
            $withoutOrgans['data'][0],
        );

        $withOrgans = $this->json($this->get(
            '/api/members',
            $token,
            [
                'itemsPerPage' => 1,
                'includeOrgans' => 'true',
            ],
        ));
        self::assertArrayHasKey(
            'organs',
            $withOrgans['data'][0],
        );
    }

    public function testAskingForBodyMembershipWithoutThePermissionAddsNothing(): void
    {
        $body = $this->json($this->get(
            '/api/members',
            $this->principalWith([ApiPermissions::MembersR]),
            [
                'itemsPerPage' => 1,
                'includeOrgans' => 'true',
            ],
        ));

        self::assertArrayNotHasKey(
            'organs',
            $body['data'][0],
        );
    }

    public function testASingleMemberCarriesBodyMembershipWithoutBeingAsked(): void
    {
        $token = $this->principalWith([
            ApiPermissions::MembersR,
            ApiPermissions::OrgansMembershipR,
        ]);

        $lidnr = $this->json($this->get(
            '/api/members',
            $token,
            ['itemsPerPage' => 1],
        ))['data'][0]['lidnr'];

        $body = $this->json($this->get(
            '/api/members/' . $lidnr,
            $token,
        ));

        self::assertArrayHasKey(
            'organs',
            $body['data'],
        );
    }

    public function testAMemberThatDoesNotExistIsAnEmptyDatasetRatherThanAMissingResource(): void
    {
        $response = $this->get(
            '/api/members/99999999',
            $this->principalWith([ApiPermissions::MembersR]),
        );

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $response->getStatusCode(),
        );
        self::assertSame(
            '',
            (string) $response->getContent(),
        );
    }

    public function testTheCollectionIsPagedAndSaysHowFarItGoes(): void
    {
        $token = $this->principalWith([ApiPermissions::MembersR]);

        $first = $this->json($this->get(
            '/api/members',
            $token,
            ['itemsPerPage' => 1],
        ));
        $second = $this->json($this->get(
            '/api/members',
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
        self::assertGreaterThan(
            32,
            $first['meta']['totalItems'],
            'the old implementation silently capped this endpoint at 32 rows',
        );
        self::assertNotSame(
            $first['data'][0]['lidnr'],
            $second['data'][0]['lidnr'],
        );
    }

    public function testAPageSizeOutOfRangeIsClampedRatherThanRefused(): void
    {
        $token = $this->principalWith([ApiPermissions::MembersR]);

        $tooLarge = $this->json($this->get(
            '/api/members',
            $token,
            ['itemsPerPage' => 100000],
        ));
        self::assertSame(
            500,
            $tooLarge['meta']['itemsPerPage'],
        );

        $tooSmall = $this->get(
            '/api/members',
            $token,
            [
                'itemsPerPage' => 0,
                'page' => 0,
            ],
        );
        self::assertSame(
            Response::HTTP_OK,
            $tooSmall->getStatusCode(),
        );
    }

    public function testActiveMembersAreNotRepeatedPerBody(): void
    {
        $body = $this->json($this->get(
            '/api/members/active',
            $this->principalWith([ApiPermissions::MembersActiveR]),
            ['itemsPerPage' => 500],
        ));

        $seen = [];
        foreach ($body['data'] as $member) {
            self::assertArrayNotHasKey(
                $member['lidnr'],
                $seen,
                'a member installed in several bodies must appear once',
            );
            $seen[$member['lidnr']] = true;
        }
    }

    public function testBirthdaysAreTheirOwnPermission(): void
    {
        $refused = $this->get(
            '/api/members/birthdays',
            $this->principalWith([ApiPermissions::MembersR]),
        );
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $refused->getStatusCode(),
        );

        $allowed = $this->get(
            '/api/members/birthdays',
            $this->principalWith([ApiPermissions::MembersBirthdaysR]),
        );
        self::assertSame(
            Response::HTTP_OK,
            $allowed->getStatusCode(),
        );
    }
}
