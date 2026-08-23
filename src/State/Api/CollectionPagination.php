<?php

declare(strict_types=1);

namespace App\State\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ArrayIterator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_key_exists;
use function filter_var;
use function intdiv;
use function is_int;
use function is_scalar;
use function max;
use function min;

use const FILTER_VALIDATE_INT;
use const PHP_INT_MAX;

final readonly class CollectionPagination
{
    public const int DEFAULT_ITEMS_PER_PAGE = 100;

    public const int MAXIMUM_ITEMS_PER_PAGE = 500;

    public function __construct(
        #[Autowire(service: 'api_platform.pagination')]
        private Pagination $pagination,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{int, int, int}
     */
    public function window(
        Operation $operation,
        array $context,
    ): array {
        $window = $this->pagination->getPagination(
            $operation,
            $this->clamp(
                $operation,
                $context,
            ),
        );

        return [
            (int) $window[0],
            (int) $window[1],
            (int) $window[2],
        ];
    }

    /**
     * @template T of object
     *
     * @param iterable<array-key, T> $items rows of this page only
     *
     * @return TraversablePaginator<T>
     */
    public function paginator(
        iterable $items,
        int $page,
        int $itemsPerPage,
        int $totalItems,
    ): TraversablePaginator {
        return new TraversablePaginator(
            $items instanceof ArrayIterator ? $items : new ArrayIterator([...$items]),
            $page,
            $itemsPerPage,
            $totalItems,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function clamp(
        Operation $operation,
        array $context,
    ): array {
        $filters = $context['filters'] ?? [];

        $filters = $this->clamped(
            $filters,
            'itemsPerPage',
            1,
            $operation->getPaginationMaximumItemsPerPage() ?? self::MAXIMUM_ITEMS_PER_PAGE,
        );
        // API Platform turns the page into the offset `($page - 1) * $itemsPerPage`, which overflows to a float and
        // aborts the request; the last page that still multiplies out to an integer answers empty instead.
        $filters = $this->clamped(
            $filters,
            'page',
            1,
            intdiv(
                PHP_INT_MAX,
                $this->itemsPerPage(
                    $operation,
                    $filters,
                ),
            ),
        );

        $context['filters'] = $filters;

        return $context;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function itemsPerPage(
        Operation $operation,
        array $filters,
    ): int {
        $requested = $filters['itemsPerPage'] ?? null;

        if (is_int($requested)) {
            return max(
                1,
                $requested,
            );
        }

        return max(
            1,
            $operation->getPaginationItemsPerPage() ?? self::DEFAULT_ITEMS_PER_PAGE,
        );
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function clamped(
        array $filters,
        string $name,
        int $minimum,
        int $maximum,
    ): array {
        if (
            !array_key_exists(
                $name,
                $filters,
            )
        ) {
            return $filters;
        }

        $value = is_scalar($filters[$name])
            ? filter_var(
                $filters[$name],
                FILTER_VALIDATE_INT,
            )
            : false;

        if (false === $value) {
            unset($filters[$name]);

            return $filters;
        }

        $filters[$name] = min(
            $maximum,
            max(
                $minimum,
                $value,
            ),
        );

        return $filters;
    }
}
