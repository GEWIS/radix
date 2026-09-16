<?php

declare(strict_types=1);

namespace App\Attribute\Application;

use Attribute;

/**
 * Marks a controller action that renders a page after an accepted submission rather than redirecting.
 *
 * {@see \App\EventListener\Application\InvalidSubmissionStatusListener} treats a rendered response to a submission
 * as a rejection and sets 422, which is correct for all but a few actions. These are those few, and in each of them
 * redirecting is not available rather than not preferred.
 *
 * Absence is the safe default, as with {@see ReadOnlySafe}, and the two mistakes differ in cost. A missing attribute
 * returns a success page with 422: the page still renders and only the logged status is wrong. A rejection returned
 * as 200 is refused by Turbo, so the errors are never displayed.
 *
 * The attribute applies to the action rather than to the response, so an action that declares it and can also reject
 * a submission must set 422 on that response itself. Both actions in
 * {@see \App\Controller\Database\ProspectiveMemberController} do so.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RendersOnSuccess
{
}
