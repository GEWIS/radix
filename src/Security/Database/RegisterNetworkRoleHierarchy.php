<?php

declare(strict_types=1);

namespace App\Security\Database;

use App\Entity\User\Enums\UserRoles;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

use function array_diff;
use function array_values;

/**
 * Withholds the register's two roles from a request that did not arrive from a network the register is open to.
 *
 * Filtering where roles are resolved covers `access_control`, `#[IsGranted]` and every `is_granted()` at once, menus
 * included. The reachable set is filtered rather than the roles handed in, so a role that one day inherits one of
 * these is covered too.
 *
 * This answers for the request in flight, so code asking what roles *another* member holds gets an answer coloured by
 * where the current visitor sits; ask {@see \App\Entity\User\User::getRoles()} there instead.
 */
#[AsDecorator(decorates: 'security.role_hierarchy')]
final readonly class RegisterNetworkRoleHierarchy implements RoleHierarchyInterface
{
    private const array REGISTER_ROLES = [
        UserRoles::DatabaseAdmin->value,
        UserRoles::DatabaseReadOnly->value,
    ];

    public function __construct(
        #[AutowireDecorated]
        private RoleHierarchyInterface $inner,
        private RegisterNetworkChecker $networkChecker,
    ) {
    }

    #[Override]
    public function getReachableRoleNames(array $roles): array
    {
        $reachable = $this->inner->getReachableRoleNames($roles);

        if ($this->networkChecker->allowsCurrentRequest()) {
            return $reachable;
        }

        return array_values(array_diff(
            $reachable,
            self::REGISTER_ROLES,
        ));
    }
}
