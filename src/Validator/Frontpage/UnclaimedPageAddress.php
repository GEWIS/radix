<?php

declare(strict_types=1);

namespace App\Validator\Frontpage;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

/**
 * The two things no single field can decide: no two pages may answer to the same address in the same language, and
 * no page may take an address the application already answers to itself.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UnclaimedPageAddress extends Constraint
{
    /**
     * The messages are given where the constraint is applied: a default here is invisible to the translation
     * extractor, and `make translations` deletes what it cannot see.
     *
     * @param string[]|null $groups
     */
    public function __construct(
        public string $reservedMessage,
        public string $takenMessage,
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
