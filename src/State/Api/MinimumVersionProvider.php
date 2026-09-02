<?php

declare(strict_types=1);

namespace App\State\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\EventListener\Api\VendorAcceptListener;
use App\Service\Report\ApiService;
use Override;
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
        $extra = $operation->getExtraProperties();
        $minimum = $extra[ApiVersion::MINIMUM] ?? null;
        $maximum = $extra[ApiVersion::MAXIMUM] ?? null;

        if ($minimum instanceof ApiVersion) {
            // The upper bound is optional, and an operation without one answers every version from its minimum
            // onwards. Deprecation carries no bound at all: it is stated in the document and enforced by nothing,
            // so a consumer that has not moved yet keeps being served.
            $this->apiService->assertVersion(
                $minimum,
                $maximum instanceof ApiVersion ? $maximum : null,
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
