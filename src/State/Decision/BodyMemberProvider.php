<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\BodyMember as BodyMemberResource;
use App\Entity\Decision\OrganMember;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Decision\OrganMemberRepository;
use App\Repository\Decision\OrganRepository;
use App\State\Api\CollectionPagination;
use App\Util\Application\QueryValue;
use DateTimeInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function is_numeric;
use function iterator_to_array;

/**
 * @implements ProviderInterface<BodyMemberResource>
 */
final readonly class BodyMemberProvider implements ProviderInterface
{
    public function __construct(
        private OrganRepository $organRepository,
        private OrganMemberRepository $organMemberRepository,
        private CollectionPagination $pagination,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, BodyMemberResource>|null
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): object|array|null {
        $id = $uriVariables['id'] ?? null;

        if (!is_numeric($id)) {
            return null;
        }

        $body = (int) $id;

        if (null === $this->organRepository->find($body)) {
            return null;
        }

        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $request = $context['request'] ?? null;

        $paginator = $this->organMemberRepository->paginateByBody(
            $body,
            QueryValue::isSet(
                $request instanceof Request ? $request : null,
                'includeDischarged',
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
     * @param iterable<array-key, OrganMember> $installations
     *
     * @return list<BodyMemberResource>
     */
    private function resources(iterable $installations): array
    {
        $resources = [];

        foreach ($installations as $installation) {
            $member = $installation->getMember();

            $resources[] = new BodyMemberResource(
                lidnr: $member->getLidnr(),
                fullName: $member->getFullName(),
                function: $installation->getFunction()->value,
                installDate: $installation->getInstallDate()->format(DateTimeInterface::ATOM),
                dischargeDate: $installation->getDischargeDate()?->format(DateTimeInterface::ATOM),
                current: $installation->isCurrent(),
            );
        }

        return $resources;
    }
}
