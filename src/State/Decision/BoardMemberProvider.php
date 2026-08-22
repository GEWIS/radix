<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\BoardMember as BoardMemberResource;
use App\Entity\Decision\BoardMember as ProjectedBoardMember;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Decision\BoardMemberRepository;
use App\State\Api\CollectionPagination;
use App\Util\Application\QueryValue;
use DateTimeInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function iterator_to_array;

/**
 * @implements ProviderInterface<BoardMemberResource>
 */
final readonly class BoardMemberProvider implements ProviderInterface
{
    public function __construct(
        private BoardMemberRepository $boardMemberRepository,
        private CollectionPagination $pagination,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, BoardMemberResource>
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

        $paginator = $this->boardMemberRepository->paginateBoardMembers(
            QueryValue::isSet(
                $request instanceof Request ? $request : null,
                'includeFormer',
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
     * @param iterable<array-key, ProjectedBoardMember> $installations
     *
     * @return list<BoardMemberResource>
     */
    private function resources(iterable $installations): array
    {
        $resources = [];

        foreach ($installations as $installation) {
            $resources[] = $this->resource($installation);
        }

        return $resources;
    }

    private function resource(ProjectedBoardMember $installation): BoardMemberResource
    {
        $member = $installation->getMember();

        return new BoardMemberResource(
            lidnr: $member->getLidnr(),
            fullName: $member->getFullName(),
            function: $installation->getFunction()->value,
            installDate: $installation->getInstallDate()->format(DateTimeInterface::ATOM),
            releaseDate: $installation->getReleaseDate()?->format(DateTimeInterface::ATOM),
            dischargeDate: $installation->getDischargeDate()?->format(DateTimeInterface::ATOM),
            current: $member->isCurrentBoard($installation),
        );
    }
}
