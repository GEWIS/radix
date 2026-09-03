<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\Body as BodyResource;
use App\Entity\Database\Enums\OrganTypes;
use App\Entity\Decision\Organ;
use App\Repository\Decision\OrganRepository;
use App\State\Api\CollectionPagination;
use App\Util\Application\QueryValue;
use DateTimeInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function is_numeric;
use function iterator_to_array;

/**
 * @implements ProviderInterface<BodyResource>
 */
final readonly class BodyProvider implements ProviderInterface
{
    public function __construct(
        private OrganRepository $organRepository,
        private CollectionPagination $pagination,
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
        $request = $context['request'] ?? null;

        return match ($operation->getName()) {
            BodyResource::OPERATION_COLLECTION => $this->all(
                $operation,
                $context,
                OrganTypes::tryFrom(QueryValue::text(
                    $request instanceof Request ? $request : null,
                    'type',
                )),
                QueryValue::isSet(
                    $request instanceof Request ? $request : null,
                    'includeAbrogated',
                ),
            ),
            default => $this->one($uriVariables),
        };
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, BodyResource>
     */
    private function all(
        Operation $operation,
        array $context,
        ?OrganTypes $type,
        bool $includeAbrogated,
    ): iterable {
        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $paginator = $this->organRepository->paginateBodies(
            $type,
            $includeAbrogated,
            $page,
            $limit,
        );

        return $this->pagination->paginator(
            $this->resources(
                iterator_to_array(
                    $paginator->getIterator(),
                    false,
                ),
            ),
            $page,
            $limit,
            $paginator->count(),
        );
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function one(array $uriVariables): ?BodyResource
    {
        $id = $uriVariables['id'] ?? null;

        if (!is_numeric($id)) {
            return null;
        }

        $body = $this->organRepository->find((int) $id);

        if (null === $body) {
            return null;
        }

        return $this->resource($body);
    }

    /**
     * @param iterable<array-key, Organ> $bodies
     *
     * @return list<BodyResource>
     */
    private function resources(iterable $bodies): array
    {
        $resources = [];

        foreach ($bodies as $body) {
            $resources[] = $this->resource($body);
        }

        return $resources;
    }

    private function resource(Organ $body): BodyResource
    {
        $id = $body->getId();
        assert(null !== $id);

        return new BodyResource(
            id: $id,
            abbreviation: $body->getAbbr(),
            name: $body->getName(),
            type: $body->getType(),
            foundationDate: $body->getFoundationDate()->format(DateTimeInterface::ATOM),
            abrogationDate: $body->getAbrogationDate()?->format(DateTimeInterface::ATOM),
            active: !$body->isAbrogated(),
        );
    }
}
