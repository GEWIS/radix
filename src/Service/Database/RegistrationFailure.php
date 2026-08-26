<?php

declare(strict_types=1);

namespace App\Service\Database;

/**
 * Why a registration did not end at a checkout page.
 */
enum RegistrationFailure: string
{
    /** The address was taken between the step that asks for it and the last one. */
    case EmailTaken = 'email-taken';

    /** The prospective member is on file, but no Checkout Session could be created for them. */
    case CheckoutUnavailable = 'checkout-unavailable';
}
