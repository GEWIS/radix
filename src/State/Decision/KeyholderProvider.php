<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\Keyholder as KeyholderResource;
use App\Entity\Decision\Keyholder as ProjectedKeyholder;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Decision\KeyholderRepository;
use App\State\Api\CollectionPagination;
use App\Util\Application\QueryValue;
use DateTimeInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function iterator_to_array;

/**
 * @implements ProviderInterface<KeyholderResource>
 */
final readonly class KeyholderProvider implements ProviderInterface
{
    public function __construct(
        private KeyholderRepository $keyholderRepository,
        private CollectionPagination $pagination,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, KeyholderResource>
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): iterable {
        $request = $context['request'] ?? null;

        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $paginator = $this->keyholderRepository->paginateKeyholders(
            QueryValue::isSet(
                $request instanceof Request ? $request : null,
                'includeExpired',
            ),
            $this->authorizationChecker->isGranted(ApiPermissions::MembersDeleted->value),
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
     * @param iterable<array-key, ProjectedKeyholder> $keyholders
     *
     * @return list<KeyholderResource>
     */
    private function resources(iterable $keyholders): array
    {
        $resources = [];

        foreach ($keyholders as $keyholder) {
            $resources[] = $this->resource($keyholder);
        }

        return $resources;
    }

    private function resource(ProjectedKeyholder $keyholder): KeyholderResource
    {
        $member = $keyholder->getMember();

        return new KeyholderResource(
            lidnr: $member->getLidnr(),
            fullName: $member->getFullName(),
            expirationDate: $keyholder->getExpirationDate()->format(DateTimeInterface::ATOM),
            withdrawnDate: $keyholder->getWithdrawnDate()?->format(DateTimeInterface::ATOM),
            current: $keyholder->isCurrent(),
        );
    }
}
