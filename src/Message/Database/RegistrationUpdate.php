<?php

declare(strict_types=1);

namespace App\Message\Database;

/**
 * What happened to a registration, of the things the (prospective) member and the secretary are notified about.
 *
 * Each case owns the template and the two subjects that belong to it, so the service that requests the mail and the
 * handler that sends it do not have to agree on a loose string.
 */
enum RegistrationUpdate: string
{
    case Registration = 'registration';
    case Welcome = 'welcome';
    case CheckoutExpired = 'checkout-expired';
    case CheckoutFailed = 'checkout-failed';
    case RefundCreated = 'refund-created';
    case PaymentLinkResent = 'payment-link-resent';

    public function template(): string
    {
        return match ($this) {
            self::Registration => 'database/email/member-registration.html.twig',
            self::Welcome => 'database/email/member-welcome.html.twig',
            self::CheckoutExpired => 'database/email/checkout-expired.html.twig',
            self::CheckoutFailed => 'database/email/checkout-failed.html.twig',
            self::RefundCreated => 'database/email/refund-created.html.twig',
            self::PaymentLinkResent => 'database/email/payment-link-resent.html.twig',
        };
    }

    /**
     * What the (prospective) member reads in their inbox.
     */
    public function subject(): string
    {
        return match ($this) {
            self::Registration => 'GEWIS registration',
            self::Welcome => 'Your GEWIS membership has been confirmed',
            self::CheckoutExpired => 'Complete your GEWIS registration',
            self::CheckoutFailed => 'Your GEWIS membership fee payment has failed',
            self::RefundCreated => 'Your GEWIS membership fee is being refunded',
            self::PaymentLinkResent => 'Complete your GEWIS registration',
        };
    }

    /**
     * The secretary receives the same message about a named person, and sorts by who it is about.
     */
    public function secretarySubject(string $fullName): string
    {
        return match ($this) {
            self::Registration => 'New member registration: ',
            self::Welcome => 'Membership confirmed: ',
            self::CheckoutExpired => 'Membership payment expired: ',
            self::CheckoutFailed => 'Membership payment failed: ',
            self::RefundCreated => 'Membership payment refund started: ',
            self::PaymentLinkResent => 'Membership payment link sent again: ',
        } . $fullName;
    }

    /**
     * Whether the update is about a member rather than a prospective member. Approval is the moment one becomes the
     * other, and the two are numbered from separate sequences, so the case decides which register the recipient is
     * read back out of.
     */
    public function isAboutMember(): bool
    {
        return self::Welcome === $this;
    }
}
