<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\User\Enums\ApiPermissions;
use App\EventListener\Api\UnexposedRouteListener;
use App\EventListener\Api\VendorAcceptListener;
use App\Security\Api\ApiExceptionListener;
use App\Security\Api\ApiTokenAuthenticator;
use App\State\Api\EnvelopeProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\HttpFoundation\Response;

use function intdiv;

use const PHP_INT_MAX;

#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(ApiTokenAuthenticator::class)]
#[CoversClass(VendorAcceptListener::class)]
#[CoversClass(EnvelopeProcessor::class)]
#[CoversClass(UnexposedRouteListener::class)]
final class ApiContractTest extends ApiTestCase
{
    public function testAnUnauthenticatedRequestIsChallengedWithoutABody(): void
    {
        $response = $this->get('/api/health');

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );
        self::assertSame(
            'Bearer realm="/api"',
            $response->headers->get('WWW-Authenticate'),
        );
        self::assertSame(
            '',
            (string) $response->getContent(),
        );
    }

    public function testAnUnknownTokenIsChallengedTheSameWay(): void
    {
        $response = $this->get(
            '/api/health',
            'not-a-token-anybody-holds',
        );

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $response->getStatusCode(),
        );
    }

    public function testHealthAnswersWithoutADataKey(): void
    {
        $response = $this->get(
            '/api/health',
            $this->principalWith([ApiPermissions::HealthR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertSame(
            'application/json; charset=utf-8',
            $response->headers->get('Content-Type'),
        );
        self::assertSame(
            [
                'status' => 'success',
                'healthy' => true,
                'sync_paused' => false,
            ],
            $this->json($response),
        );
    }

    public function testAMissingPermissionNamesItselfInTheBody(): void
    {
        $response = $this->get(
            '/api/health',
            $this->principalWith([ApiPermissions::MembersR]),
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
                    'exception' => 'Permission `health_read` is needed but is not currently held.',
                ],
            ],
            $this->json($response),
        );
    }

    public function testTheWildcardGrantsEveryPermission(): void
    {
        $response = $this->get(
            '/api/health',
            $this->principalWith([ApiPermissions::All]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
    }

    public function testAnAddressThatAnswersNothingSaysSo(): void
    {
        $response = $this->get(
            '/api/example404',
            $this->principalWith([ApiPermissions::All]),
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

    public function testAFailingEndpointIsReportedAsAnError(): void
    {
        $response = $this->get(
            '/api/example500',
            $this->principalWith([ApiPermissions::All]),
        );

        self::assertSame(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $response->getStatusCode(),
        );
        self::assertSame(
            [
                'status' => 'error',
                'error' => [
                    'type' => 'RuntimeException',
                    'exception' => 'An example exception was thrown.',
                ],
            ],
            $this->json($response),
        );
    }

    public function testTheVendorAcceptHeaderDoesNotRefuseAResourceEndpoint(): void
    {
        $response = $this->get(
            '/api/members',
            $this->principalWith([ApiPermissions::MembersR]),
            ['itemsPerPage' => 1],
            ['HTTP_ACCEPT' => 'application/vnd.gewis.gewisdb+json;version=4.3.3'],
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            'a consumer that negotiates a version for the function lists sends the same header everywhere',
        );
    }

    public function testAnEndpointOlderThanTheVersionedContractStillAnswersWithoutOne(): void
    {
        $response = $this->get(
            '/api/members',
            $this->principalWith([ApiPermissions::MembersR]),
            ['itemsPerPage' => 1],
            withVersion: false,
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
            'the member endpoints answered before the contract was versioned and have to keep doing so',
        );
    }

    public function testAnEndpointAddedWithTheVersionedContractRequiresOne(): void
    {
        $response = $this->get(
            '/api/bodies',
            $this->principalWith([ApiPermissions::BodiesR]),
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

    public function testAVersionOlderThanTheContractIsRefused(): void
    {
        $response = $this->get(
            '/api/bodies',
            $this->principalWith([ApiPermissions::BodiesR]),
            headers: ['HTTP_ACCEPT' => 'application/vnd.gewis.gewisdb+json;version=4.3.3'],
        );

        self::assertSame(
            Response::HTTP_NOT_ACCEPTABLE,
            $response->getStatusCode(),
        );
        self::assertSame(
            'API version must be at least 5.0.0, but 4.3.3 was given.',
            $this->json($response)['error']['exception'],
        );
    }

    public function testAMissingPermissionIsReportedBeforeAMissingVersion(): void
    {
        $response = $this->get(
            '/api/bodies',
            $this->principalWith([ApiPermissions::MembersR]),
            withVersion: false,
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
            'the hand-written endpoints answer in that order, and these have to agree with them',
        );
    }

    public function testTheVersionCanAlsoBeGivenInAHeaderTheDocumentCanDeclare(): void
    {
        foreach (
            [
                '/api/bodies' => ApiPermissions::BodiesR,
                '/api/organFunctions' => ApiPermissions::OrganFunctionsListR,
            ] as $path => $permission
        ) {
            $response = $this->get(
                $path,
                $this->principalWith([$permission]),
                headers: ['HTTP_X_API_VERSION' => '5.0.0'],
                withVersion: false,
            );

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
                $path . ' must accept the version through the header Swagger UI can send',
            );
        }
    }

    public function testEvenAPlumbingRouteChallengesACallerWithoutAToken(): void
    {
        foreach (
            [
                '/api/validation_errors/deadbeef',
                '/api/.well-known/genid/deadbeef',
            ] as $path
        ) {
            $anonymous = $this->get($path);
            self::assertSame(
                Response::HTTP_UNAUTHORIZED,
                $anonymous->getStatusCode(),
                $path,
            );
            self::assertSame(
                '',
                $anonymous->getContent(),
                $path,
            );

            self::assertSame(
                Response::HTTP_NOT_FOUND,
                $this->get(
                    $path,
                    $this->principalWith([ApiPermissions::BodiesR]),
                )->getStatusCode(),
                $path,
            );
        }
    }

    public function testAnAcceptHeaderThatNamesTheVendorTypeAlongsideOthersIsStillUnderstood(): void
    {
        foreach (
            [
                'first' => self::VERSIONED_ACCEPT . ', text/html;q=0.9, */*;q=0.8',
                'last' => 'text/html;q=0.9, */*;q=0.8, ' . self::VERSIONED_ACCEPT,
                'spaced' => 'text/html , ' . self::VERSIONED_ACCEPT . ' , */*',
            ] as $shape => $accept
        ) {
            $response = $this->get(
                '/api/bodies',
                $this->principalWith([ApiPermissions::BodiesR]),
                ['itemsPerPage' => 1],
                ['HTTP_ACCEPT' => $accept],
            );

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
                $shape,
            );
        }
    }

    public function testAPageBeyondWhatAnOffsetCanHoldAnswersEmptyRatherThanFailing(): void
    {
        foreach (
            [
                'the largest integer there is' => PHP_INT_MAX,
                'one that overflows once multiplied out' => intdiv(
                    PHP_INT_MAX,
                    2,
                ),
            ] as $shape => $page
        ) {
            $response = $this->get(
                '/api/bodies',
                $this->principalWith([ApiPermissions::BodiesR]),
                [
                    'page' => $page,
                    'itemsPerPage' => 500,
                ],
            );

            self::assertSame(
                Response::HTTP_OK,
                $response->getStatusCode(),
                $shape,
            );
            self::assertSame(
                [],
                $this->json($response)['data'],
                $shape,
            );
        }
    }

    public function testAVersionIsExpectedOnTheFunctionLists(): void
    {
        $token = $this->principalWith([ApiPermissions::OrganFunctionsListR]);

        $response = $this->get(
            '/api/organFunctions',
            $token,
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

        $accepted = $this->get(
            '/api/organFunctions',
            $token,
            headers: ['HTTP_ACCEPT' => 'application/vnd.gewis.gewisdb+json;version=4.3.3'],
        );

        self::assertSame(
            Response::HTTP_OK,
            $accepted->getStatusCode(),
        );

        $body = $this->json($accepted);
        self::assertSame(
            'success',
            $body['status'],
        );
        self::assertArrayHasKey(
            'Voorzitter',
            $body['data'],
        );
    }
}
