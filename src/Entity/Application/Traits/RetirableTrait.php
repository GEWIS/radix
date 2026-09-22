<?php

declare(strict_types=1);

namespace App\Entity\Application\Traits;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Override;

/**
 * Retirement for the shared reference data of {@see \App\Entity\Application\LabelInterface}, which is kept where it is
 * already applied and is no longer offered anywhere else. Whether something is still in use is left to the entity,
 * because only it knows the collection that answers it.
 */
trait RetirableTrait
{
    #[Column(
        type: Types::BOOLEAN,
        options: ['default' => false],
    )]
    public private(set) bool $retired = false;

    #[Override]
    public function retire(): void
    {
        $this->retired = true;
    }

    #[Override]
    public function restore(): void
    {
        $this->retired = false;
    }
}
