<?php

declare(strict_types=1);

namespace App\State\Api;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Error;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\OpenApi\OpenApi;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Application\Enums\ApiResponseStatuses;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * @implements ProcessorInterface<mixed, mixed>
 */
#[AsDecorator(
    decorates: 'api_platform.state_processor.main',
    priority: 250,
)]
final readonly class EnvelopeProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<mixed, mixed> $processor
     */
    public function __construct(
        #[AutowireDecorated]
        private ProcessorInterface $processor,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    #[Override]
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): mixed {
        if (
            $this->wraps(
                $data,
                $operation,
            )
        ) {
            $data = '{"status":"' . ApiResponseStatuses::Success->value . '","data":' . $data
                . $this->meta(
                    $operation,
                    $context,
                ) . '}';
        }

        return $this->processor->process(
            $data,
            $operation,
            $uriVariables,
            $context,
        );
    }

    private function wraps(
        mixed $data,
        Operation $operation,
    ): bool {
        return is_string($data)
            && $operation instanceof HttpOperation
            && !($operation instanceof Error)
            && OpenApi::class !== $operation->getClass();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function meta(
        Operation $operation,
        array $context,
    ): string {
        $original = $context['original_data'] ?? null;

        if (
            !$operation instanceof CollectionOperationInterface
            || !$original instanceof PaginatorInterface
        ) {
            return '';
        }

        return ',"meta":' . json_encode(
            [
                'page' => (int) $original->getCurrentPage(),
                'itemsPerPage' => (int) $original->getItemsPerPage(),
                'totalItems' => (int) $original->getTotalItems(),
                'totalPages' => (int) $original->getLastPage(),
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
