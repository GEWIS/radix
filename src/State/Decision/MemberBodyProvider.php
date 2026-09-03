<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\BodySummary;
use App\ApiResource\Decision\MemberBody as MemberBodyResource;
use App\Entity\Decision\OrganMember;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Decision\MemberRepository;
use App\Repository\Decision\OrganMemberRepository;
use App\State\Api\CollectionPagination;
use App\Util\Application\QueryValue;
use DateTimeInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function assert;
use function is_numeric;
use function iterator_to_array;

/**
 * @implements ProviderInterface<MemberBodyResource>
 */
final readonly class MemberBodyProvider implements ProviderInterface
{
    public function __construct(
        private MemberRepository $memberRepository,
        private OrganMemberRepository $organMemberRepository,
        private CollectionPagination $pagination,
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return iterable<array-key, MemberBodyResource>|null
     */
    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): object|array|null {
        $lidnr = $uriVariables['lidnr'] ?? null;

        if (!is_numeric($lidnr)) {
            return null;
        }

        $member = $this->memberRepository->findSimple((int) $lidnr);

        if (
            null === $member
            || (
                $member->getDeleted()
                && !$this->authorizationChecker->isGranted(ApiPermissions::MembersDeleted->value)
            )
        ) {
            return null;
        }

        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $request = $context['request'] ?? null;

        $paginator = $this->organMemberRepository->paginateByMember(
            $member->getLidnr(),
            QueryValue::isSet(
                $request instanceof Request ? $request : null,
                'includeDischarged',
            ),
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
     * @return list<MemberBodyResource>
     */
    private function resources(iterable $installations): array
    {
        $resources = [];

        foreach ($installations as $installation) {
            $body = $installation->getOrgan();
            $id = $body->getId();
            assert(null !== $id);

            $resources[] = new MemberBodyResource(
                body: new BodySummary(
                    id: $id,
                    abbreviation: $body->getAbbr(),
                    name: $body->getName(),
                    type: $body->getType(),
                ),
                function: $installation->getFunction(),
                installDate: $installation->getInstallDate()->format(DateTimeInterface::ATOM),
                dischargeDate: $installation->getDischargeDate()?->format(DateTimeInterface::ATOM),
                current: $installation->isCurrent(),
            );
        }

        return $resources;
    }
}
