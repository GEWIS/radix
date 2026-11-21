<?php

declare(strict_types=1);

namespace App\Tests\Entity\User\Traits;

use App\Entity\User\Traits\BackupCodeAwareTrait;

/**
 * A host with no members of its own, so the trait's own tests see exactly what the trait adds.
 */
final class BackupCodeAwareHolder
{
    use BackupCodeAwareTrait;
}
