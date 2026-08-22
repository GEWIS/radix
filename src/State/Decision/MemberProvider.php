<?php

declare(strict_types=1);

namespace App\State\Decision;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Decision\Member as MemberResource;
use App\Entity\Decision\Member as ProjectedMember;
use App\Entity\Decision\OrganMember;
use App\Entity\User\Enums\ApiPermissions;
use App\Repository\Decision\MemberRepository;
use App\Serializer\Api\MemberSerializationGroups;
use App\State\Api\CollectionPagination;
use App\Util\Application\QueryValue;
use DateTimeInterface;
use Override;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_filter;
use function array_map;
use function array_values;
use function iterator_to_array;
use function max;
use function min;

/**
 * @implements ProviderInterface<object>
 */
final readonly class MemberProvider implements ProviderInterface
{
    private const int MAXIMUM_BIRTHDAY_DAYS = 31;

    public function __construct(
        private MemberRepository $memberRepository,
        private CollectionPagination $pagination,
        private MemberSerializationGroups $groups,
        private AuthorizationCheckerInterface $authorizationChecker,
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
    ): object|array {
        $request = $context['request'] ?? null;
        $groups = $this->groups->for(
            $request instanceof Request ? $request : null,
            $operation,
        );

        return match ($operation->getName()) {
            MemberResource::OPERATION_COLLECTION => $this->normal(
                $operation,
                $context,
                $groups,
            ),
            MemberResource::OPERATION_ACTIVE => $this->active(
                $operation,
                $context,
                $groups,
                QueryValue::isSet(
                    $request instanceof Request ? $request : null,
                    'includeInactive',
                ),
            ),
            MemberResource::OPERATION_BIRTHDAYS => $this->birthdays(
                $operation,
                $context,
                $groups,
                QueryValue::number(
                    $request instanceof Request ? $request : null,
                    'days',
                ),
            ),
            default => $this->one(
                $uriVariables,
                $groups,
            ),
        };
    }

    /**
     * @param array<string, mixed> $context
     * @param string[]             $groups
     *
     * @return iterable<array-key, MemberResource>
     */
    private function normal(
        Operation $operation,
        array $context,
        array $groups,
    ): iterable {
        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $paginator = $this->memberRepository->paginateNormal(
            $page,
            $limit,
        );

        return $this->pagination->paginator(
            $this->resources(
                iterator_to_array(
                    $paginator->getIterator(),
                    false,
                ),
                $groups,
            ),
            $page,
            $limit,
            $paginator->count(),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param string[]             $groups
     *
     * @return iterable<array-key, MemberResource>
     */
    private function active(
        Operation $operation,
        array $context,
        array $groups,
        bool $includeInactiveFraternity,
    ): iterable {
        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $page = $window[0];
        $limit = $window[2];

        $paginator = $this->memberRepository->paginateActive(
            $includeInactiveFraternity,
            $this->allowsDeletedMembers(),
            $page,
            $limit,
        );

        return $this->pagination->paginator(
            $this->resources(
                iterator_to_array(
                    $paginator->getIterator(),
                    false,
                ),
                $groups,
            ),
            $page,
            $limit,
            $paginator->count(),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param string[]             $groups
     *
     * @return iterable<array-key, MemberResource>
     */
    private function birthdays(
        Operation $operation,
        array $context,
        array $groups,
        int $days,
    ): iterable {
        $window = $this->pagination->window(
            $operation,
            $context,
        );
        $offset = $window[1];
        $limit = $window[2];

        $members = $this->memberRepository->findBirthdayMembers(
            min(
                self::MAXIMUM_BIRTHDAY_DAYS,
                max(
                    0,
                    $days,
                ),
            ),
        );

        return new ArrayPaginator(
            $this->resources(
                $members,
                $groups,
            ),
            $offset,
            $limit,
        );
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param string[]             $groups
     */
    private function one(
        array $uriVariables,
        array $groups,
    ): MemberResource|Response {
        $lidnr = $uriVariables['lidnr'] ?? null;
        $member = null;

        if (null !== $lidnr) {
            $member = $this->memberRepository->findSimple((int) $lidnr);
        }

        if (
            null === $member
            || (
                $member->getDeleted()
                && !$this->allowsDeletedMembers()
            )
        ) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return $this->resource(
            $member,
            $groups,
        );
    }

    /**
     * @param iterable<array-key, ProjectedMember> $members
     * @param string[]                             $groups
     *
     * @return list<MemberResource>
     */
    private function resources(
        iterable $members,
        array $groups,
    ): array {
        $resources = [];

        foreach ($members as $member) {
            $resources[] = $this->resource(
                $member,
                $groups,
            );
        }

        return $resources;
    }

    /**
     * @param string[] $groups
     */
    private function resource(
        ProjectedMember $member,
        array $groups,
    ): MemberResource {
        return new MemberResource(
            lidnr: $member->getLidnr(),
            fullName: $member->getFullName(),
            familyName: $member->getLastName(),
            middleName: $member->getMiddleName(),
            initials: $member->getInitials(),
            givenName: $member->getFirstName(),
            generation: $member->getGeneration(),
            hidden: $member->getHidden(),
            deleted: $member->getDeleted(),
            expiration: $member->getExpiration()->format(DateTimeInterface::ATOM),
            organs: $this->groups->has(
                $groups,
                MemberResource::GROUP_ORGANS,
            )
                ? $this->organs($member)
                : [],
            email: $member->getEmail(),
            birthdate: $member->getBirth()->format(DateTimeInterface::ATOM),
            is16Plus: $member->hasReached16(),
            is18Plus: $member->hasReached18(),
            is21Plus: $member->hasReached21(),
            keyholder: $this->groups->has(
                $groups,
                MemberResource::GROUP_KEYHOLDER,
            )
                ? $member->isKeyholder()
                : null,
            membershipType: $member->getType()->value,
        );
    }

    /**
     * @return array<array-key, array{
     *     organ: array{id: int|null, abbreviation: string},
     *     function: string,
     *     installDate: string,
     *     dischargeDate: string|null,
     *     current: bool,
     * }>
     */
    private function organs(ProjectedMember $member): array
    {
        return array_values(
            array_map(
                static function (OrganMember $installation): array {
                    return $installation->toArray();
                },
                array_filter(
                    $member->getOrganInstallations()->toArray(),
                    static function (OrganMember $installation): bool {
                        return $installation->isCurrent();
                    },
                ),
            ),
        );
    }

    private function allowsDeletedMembers(): bool
    {
        return $this->authorizationChecker->isGranted(ApiPermissions::MembersDeleted->value);
    }
}
