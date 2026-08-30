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
    /**
     * The messages are given where the constraint is applied: a default here is invisible to the translation
     * extractor, and `make translations` deletes what it cannot see.
     *
     * @param string[]|null $groups
     */
    public function __construct(
        public string $closesBeforeOpeningMessage,
        public string $outlivesPackageMessage,
        public string $slugTakenMessage,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(
            null,
            $groups,
            $payload,
        );
    }

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
