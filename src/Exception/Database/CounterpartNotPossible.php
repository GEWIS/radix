<?php

declare(strict_types=1);

namespace App\Exception\Database;

use RuntimeException;

/**
 * Thrown when a decision cannot be said to repeat another one.
 *
 * Only a decision taken in a virtual meeting repeats anything, and only a decision taken in a real meeting is worth
 * repeating: a virtual meeting exists to put on the record what a real one decided, so a chain of virtual decisions
 * pointing at each other says nothing.
 */
class CounterpartNotPossible extends RuntimeException
{
}
