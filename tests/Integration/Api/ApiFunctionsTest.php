<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Application\Enums\ApiResponseStatuses;
use App\Entity\User\Enums\ApiPermissions;
use App\Service\Api\ApiService;
use Symfony\Component\HttpFoundation\Response;

use function array_keys;

/**
 * The hand-written endpoints beside the API Platform resources: the two function lists, the sync pause in
 * the health response, and the not-found envelope.
 */
final class ApiFunctionsTest extends ApiTestCase
{
    public function testTheOrganFunctionsAreListedWithTheirTranslations(): void
    {
        $response = $this->get(
            '/api/organFunctions',
            $this->principalWith([ApiPermissions::OrganFunctionsListR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );

        $body = $this->json($response);
        self::assertSame(
            ApiResponseStatuses::Success->value,
            $body['status'],
        );
        self::assertIsArray($body['data']);
        self::assertNotEmpty($body['data']);

        $function = $body['data'][array_keys($body['data'])[0]];
        self::assertArrayHasKey(
            'translations',
            $function,
        );
        self::assertArrayHasKey(
            'isLegacy',
            $function,
        );
    }

    public function testTheBoardFunctionsAreListed(): void
    {
        $response = $this->get(
            '/api/boardFunctions',
            $this->principalWith([ApiPermissions::BoardFunctionsListR]),
        );

        self::assertSame(
            Response::HTTP_OK,
            $response->getStatusCode(),
        );
        self::assertNotEmpty($this->json($response)['data']);
    }

    public function testAFunctionListRequiresItsOwnPermission(): void
    {
        $response = $this->get(
            '/api/organFunctions',
            $this->principalWith([ApiPermissions::BoardFunctionsListR]),
        );

        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $response->getStatusCode(),
        );
        self::assertSame(
            'Permission `organs_functionslist_read` is needed but is not currently held.',
            $this->json($response)['error']['exception'],
        );
    }

    public function testAFunctionListRequiresTheVersionedContract(): void
    {
        $response = $this->get(
            '/api/organFunctions',
            $this->principalWith([ApiPermissions::OrganFunctionsListR]),
            withVersion: false,
        );

        self::assertSame(
            Response::HTTP_NOT_ACCEPTABLE,
            $response->getStatusCode(),
        );
    }

    public function testAnAddressNothingServesIsReportedAsMissing(): void
    {
        $response = $this->get(
            '/api/this/does/not/exist',
            $this->principalWith([ApiPermissions::HealthR]),
        );

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $response->getStatusCode(),
        );
        self::assertSame(
            ApiResponseStatuses::NotFound->value,
            $this->json($response)['status'],
        );
    }

    /**
     * A consumer checks the flag before a synchronisation, so the pause must appear in the health response, not only
     * in the administration screen.
     */
    public function testAPausedSyncIsReportedByHealth(): void
    {
        $api = self::getContainer()->get(ApiService::class);
        self::assertInstanceOf(
            ApiService::class,
            $api,
        );
        $api->pauseSync(15);

        $body = $this->json($this->get(
            '/api/health',
            $this->principalWith([ApiPermissions::HealthR]),
        ));

        self::assertTrue($body['sync_paused']);
    }
}
