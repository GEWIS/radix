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
    public string $reservedMessage = 'The website already answers to this address, so a page cannot take it.';

    public string $takenMessage = 'Another page already answers to this address.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
