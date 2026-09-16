<?php

declare(strict_types=1);

namespace App\Attribute\Application;

use Attribute;

/**
 * Marks a controller action that answers an accepted submission by rendering a page rather than by redirecting.
 *
 * {@see \App\EventListener\Application\InvalidSubmissionStatusListener} reads a rendered page from a submission as a
 * rejected one and answers 422, because that is what all but a handful of actions here mean by it. These are that
 * handful, and each one is a place where redirecting is not available rather than a place where it was not preferred.
 *
 * Absence is the safe default, as with {@see ReadOnlySafe}, and the two mistakes are not the same size. An action
 * that should carry this and does not serves its success page as 422: the page still renders, and the cost is a
 * wrong status in the logs. An action that renders a rejection as 200 is refused by Turbo, and the member sees
 * nothing happen at all.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RendersOnSuccess
{
}
