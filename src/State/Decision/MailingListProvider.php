<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\MailingList as MailingListResource;
use App\Entity\Decision\MailingList as ProjectedMailingList;
use App\Repository\Decision\MailingListRepository;
use App\State\Api\CollectionPagination;
use Override;

use function is_string;
use function iterator_to_array;

/**
 * @implements ProviderInterface<MailingListResource>
 */
final readonly class MailingListProvider implements ProviderInterface
{
    public function __construct(
        private MailingListRepository $mailingListRepository,
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
        return match ($operation->getName()) {
            MailingListResource::OPERATION_COLLECTION => $this->all(
                $operation,
                $context,
            ),
            default => $this->one($uriVariables),
        };
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, MailingListResource>
     */
    private function all(
        Operation $operation,
        array $context,
    ): iterable {
        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $paginator = $this->mailingListRepository->paginateLists(
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
    private function one(array $uriVariables): ?MailingListResource
    {
        $name = $uriVariables['name'] ?? null;

        if (!is_string($name)) {
            return null;
        }

        $list = $this->mailingListRepository->find($name);

        // The lookup runs against a case-insensitive collation, but the name is the identifier and the document says
        // it is case-sensitive: one resource under an unbounded number of addresses is not that.
        if (
            null === $list
            || $list->getName() !== $name
        ) {
            return null;
        }

        return $this->resource($list);
    }

    /**
     * @param iterable<array-key, ProjectedMailingList> $lists
     *
     * @return list<MailingListResource>
     */
    private function resources(iterable $lists): array
    {
        $resources = [];

        foreach ($lists as $list) {
            $resources[] = $this->resource($list);
        }

        return $resources;
    }

    private function resource(ProjectedMailingList $list): MailingListResource
    {
        return new MailingListResource(
            name: $list->getName(),
            description: [
                'en' => $list->getEnDescription(),
                'nl' => $list->getNlDescription(),
            ],
        );
    }
}
