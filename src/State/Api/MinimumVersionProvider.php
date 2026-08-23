<?php

declare(strict_types=1);

namespace App\State\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\EventListener\Api\VendorAcceptListener;
use App\Service\Report\ApiService;
use Override;
use PHLAK\SemVer\Version as SemanticVersion;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * @implements ProviderInterface<object>
 */
#[AsDecorator(
    decorates: 'api_platform.state_provider.read',
    priority: 20,
)]
final readonly class MinimumVersionProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<object> $decorated
     */
    public function __construct(
        #[AutowireDecorated]
        private ProviderInterface $decorated,
        private ApiService $apiService,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): object|array|null {
        $minimum = $operation->getExtraProperties()[ApiVersion::MINIMUM] ?? null;

        if (is_string($minimum)) {
            $this->apiService->assertVersion(
                new SemanticVersion($minimum),
                null,
                $this->negotiated($context),
            );
        }

        return $this->decorated->provide(
            $operation,
            $uriVariables,
            $context,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function negotiated(array $context): ?string
    {
        $request = $context['request'] ?? null;

        if (!($request instanceof Request)) {
            return null;
        }

        $accept = $request->attributes->get(VendorAcceptListener::NEGOTIATED_VERSION);

        return is_string($accept)
            ? $accept
            : null;
    }
}
