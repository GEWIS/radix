<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Activity\SignupList;
use App\Entity\Activity\SignupRole;

trait BuildsSignupRoles
{
    private function role(
        SignupList $list,
        string $name,
        int $minimum,
    ): SignupRole {
        $role = new SignupRole();
        $role->setName($name);
        $role->setMinimum($minimum);
        $list->addRole($role);

        return $role;
    }
}
