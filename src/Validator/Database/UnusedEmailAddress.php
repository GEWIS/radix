<?php

declare(strict_types=1);

namespace App\Validator\Database;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that no member and no other registration already answers to this e-mail address.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class UnusedEmailAddress extends Constraint
{
    public const string ALREADY_USED_ERROR = '2f5a1c74-6b3e-4f8d-9a02-7c1e4b6d3f58';

    protected const array ERROR_NAMES = [self::ALREADY_USED_ERROR => 'ALREADY_USED_ERROR'];

    public string $message = 'There already is a member with this e-mail address.';

    /**
     * @param string[]|null        $groups
     * @param array<string, mixed> $options
     */
    public function __construct(
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
        array $options = [],
    ) {
        parent::__construct(
            $options,
            $groups,
            $payload,
        );

        $this->message = $message ?? $this->message;
    }
}
