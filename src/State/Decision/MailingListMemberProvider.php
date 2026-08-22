<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\MailingListMember as MailingListMemberResource;
use App\Entity\Decision\MailingListMember as ProjectedMailingListMember;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Decision\MailingListMemberRepository;
use App\Repository\Decision\MailingListRepository;
use App\State\Api\CollectionPagination;
use Override;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function is_string;
use function iterator_to_array;

/**
 * @implements ProviderInterface<MailingListMemberResource>
 */
final readonly class MailingListMemberProvider implements ProviderInterface
{
    public function __construct(
        private MailingListRepository $mailingListRepository,
        private MailingListMemberRepository $mailingListMemberRepository,
        private CollectionPagination $pagination,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, MailingListMemberResource>|null
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): object|array|null {
        $name = $uriVariables['name'] ?? null;

        if (!is_string($name)) {
            return null;
        }

        // The collation is case-insensitive; the identifier is not. See MailingListProvider::one().
        if ($this->mailingListRepository->find($name)?->getName() !== $name) {
            return null;
        }

        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $paginator = $this->mailingListMemberRepository->paginateSubscribers(
            $name,
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
     * @param iterable<array-key, ProjectedMailingListMember> $subscriptions
     *
     * @return list<MailingListMemberResource>
     */
    private function resources(iterable $subscriptions): array
    {
        $resources = [];

        foreach ($subscriptions as $subscription) {
            $member = $subscription->getMember();

            $resources[] = new MailingListMemberResource(
                lidnr: $member->getLidnr(),
                fullName: $member->getFullName(),
                email: $subscription->getEmail(),
            );
        }

        return $resources;
    }
}
