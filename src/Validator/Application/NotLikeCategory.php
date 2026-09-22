<?php

declare(strict_types=1);

namespace App\Validator\Application;

use Attribute;
use Symfony\Component\Validator\Constraint;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * A name that is too similar to one of the categories. A revision already has a category, so a label with a category
 * name is either that category or a copy of it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class NotLikeCategory extends Constraint
{
    /**
     * The message is an argument rather than a default, because the translation extractor only reads a message
     * written where the constraint is applied.
     *
     * @param list<TranslatableInterface> $categories
     * @param string[]|null               $groups
     */
    public function __construct(
        public array $categories,
        public string $message,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(
            null,
            $groups,
            $payload,
        );
    }
}
