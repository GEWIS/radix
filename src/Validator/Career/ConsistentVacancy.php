<?php

declare(strict_types=1);

namespace App\Validator\Career;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

/**
 * The three things no single field can decide: a vacancy cannot close before it opens, cannot stay open past the job
 * package it is sold under, and needs a slug that is free within its company and category.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ConsistentVacancy extends Constraint
{
    public string $closesBeforeOpeningMessage = 'The vacancy cannot close before it opens.';

    public string $outlivesPackageMessage = 'The vacancy cannot stay open past the job package it belongs to.';

    public string $slugTakenMessage = 'Another vacancy of this company already uses this slug in this category.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
