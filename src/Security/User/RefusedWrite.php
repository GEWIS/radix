<?php

declare(strict_types=1);

namespace App\Security\User;

/**
 * What confirming sudo does with the write the user was refused, which the prompt states so that pressing Confirm is
 * not the only thing they learn about it.
 */
enum RefusedWrite
{
    /** Nothing was kept, so confirming only opens the page again. */
    case None;

    /** The action declares {@see \App\Attribute\User\Replayable} and the write is sent as part of confirming. */
    case SentOnConfirmation;

    /** The write is not sent again, and the user submits it themselves once they are back on the page. */
    case SubmittedByHand;
}
