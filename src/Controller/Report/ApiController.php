<?php

declare(strict_types=1);

namespace App\Controller\Report;

use App\Entity\Application\Enums\ApiResponseStatuses;
use App\Entity\User\Enums\ApiPermissions;
use App\EventListener\Api\VendorAcceptListener;
use App\Service\Report\ApiService;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_string;

/**
 * The `/api` endpoints that are not API Platform resources. Their envelope and status codes are a contract with the
 * other GEWIS applications.
 */
#[Route(path: '/api')]
final class ApiController extends AbstractController
{
    public function __construct(private readonly ApiService $apiService)
    {
    }

    #[Route(
        path: '',
        name: 'api_index',
        methods: ['GET'],
    )]
    #[Route(
        path: '/health',
        name: 'api_health',
        methods: ['GET'],
    )]
    #[IsGranted(ApiPermissions::HealthR->value)]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => ApiResponseStatuses::Success->value,
            'healthy' => true,
            'sync_paused' => $this->apiService->isSyncPaused(),
        ]);
    }

    #[Route(
        path: '/example500',
        name: 'api_example500',
        methods: ['GET'],
    )]
    public function example500(): never
    {
        throw new RuntimeException('An example exception was thrown.');
    }

    #[Route(
        path: '/organFunctions',
        name: 'api_organ_functions',
        methods: ['GET'],
    )]
    #[IsGranted(ApiPermissions::OrganFunctionsListR->value)]
    public function organFunctions(Request $request): JsonResponse
    {
        return new JsonResponse([
            'status' => ApiResponseStatuses::Success->value,
            'data' => $this->apiService->getOrganFunctions($this->negotiatedVersion($request)),
        ]);
    }

    #[Route(
        path: '/boardFunctions',
        name: 'api_board_functions',
        methods: ['GET'],
    )]
    #[IsGranted(ApiPermissions::BoardFunctionsListR->value)]
    public function boardFunctions(Request $request): JsonResponse
    {
        return new JsonResponse([
            'status' => ApiResponseStatuses::Success->value,
            'data' => $this->apiService->getBoardFunctions($this->negotiatedVersion($request)),
        ]);
    }

    private function negotiatedVersion(Request $request): ?string
    {
        $version = $request->attributes->get(VendorAcceptListener::NEGOTIATED_VERSION);

        return is_string($version)
            ? $version
            : null;
    }

    #[Route(
        path: '/{wildcard}',
        name: 'api_not_found',
        requirements: ['wildcard' => '.*'],
    )]
    public function notFound(string $wildcard): never
    {
        throw new NotFoundHttpException(
            '/api/' . $wildcard . ' does not exist.',
            new ResourceNotFoundException(),
        );
    }
}
