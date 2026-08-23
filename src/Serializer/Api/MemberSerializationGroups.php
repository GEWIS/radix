<?php

declare(strict_types=1);

namespace App\Serializer\Api;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\Decision\Member;
use App\Entity\User\Enums\ApiPermissions;
use App\Util\Application\QueryValue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function in_array;

final readonly class MemberSerializationGroups
{
    private const array PROPERTY_GROUPS = [
        ApiPermissions::OrgansMembershipR->value => Member::GROUP_ORGANS,
        ApiPermissions::MembersPropertyEmail->value => Member::GROUP_EMAIL,
        ApiPermissions::MembersPropertyBirthDate->value => Member::GROUP_BIRTHDATE,
        ApiPermissions::MembersPropertyAge16->value => Member::GROUP_AGE_16,
        ApiPermissions::MembersPropertyAge18->value => Member::GROUP_AGE_18,
        ApiPermissions::MembersPropertyAge21->value => Member::GROUP_AGE_21,
        ApiPermissions::MembersPropertyKeyholder->value => Member::GROUP_KEYHOLDER,
        ApiPermissions::MembersPropertyType->value => Member::GROUP_TYPE,
    ];

    public function __construct(private AuthorizationCheckerInterface $authorizationChecker)
    {
    }

    /**
     * @return string[]
     */
    public function for(
        ?Request $request,
        ?Operation $operation,
    ): array {
        $groups = [Member::GROUP_READ];

        foreach (
            self::PROPERTY_GROUPS as $permission => $group
        ) {
            if (!$this->authorizationChecker->isGranted($permission)) {
                continue;
            }

            if (
                Member::GROUP_ORGANS === $group
                && !$this->includesOrgans(
                    $request,
                    $operation,
                )
            ) {
                continue;
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param string[] $groups
     */
    public function has(
        array $groups,
        string $group,
    ): bool {
        return in_array(
            $group,
            $groups,
            true,
        );
    }

    private function includesOrgans(
        ?Request $request,
        ?Operation $operation,
    ): bool {
        if (Member::OPERATION_COLLECTION !== $operation?->getName()) {
            return true;
        }

        return QueryValue::isSet(
            $request,
            'includeOrgans',
        );
    }
}
