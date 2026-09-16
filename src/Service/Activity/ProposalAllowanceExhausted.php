<?php

declare(strict_types=1);

namespace App\Service\Activity;

use RuntimeException;

/**
 * A body ran out of room for another proposal between the form being filled in and it being submitted.
 *
 * Rare by nature: the form already says how much room is left, so this only happens when two people from one body
 * submit a proposal at the same moment. The caller turns it into a message rather than a stack trace.
 */
final class ProposalAllowanceExhausted extends RuntimeException
{
}
