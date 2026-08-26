<?php

declare(strict_types=1);

namespace App\Exception\Database;

use RuntimeException;

/**
 * Thrown when a decision cannot be deleted because it names a member who has been deleted.
 *
 * A deleted member is kept only so the decisions naming them can be kept; removing one of those decisions leaves
 * their record standing for a reason that is no longer there. That is a correction to be made deliberately rather
 * than as the side effect of removing a decision, so the removal is refused.
 */
class DecisionNamesDeletedMember extends RuntimeException
{
}
