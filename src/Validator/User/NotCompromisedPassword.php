<?php

declare(strict_types=1);

namespace App\Validator\User;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that a password does not appear in a known data breach. Symfony ships a constraint of the same name, but it
 * speaks haveibeenpwned's k-anonymity protocol: it asks for the first five characters of the hash and searches the
 * range that comes back. The association runs its own lookup, which answers for the whole hash instead, so the two
 * cannot be reconciled through the endpoint setting alone.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class NotCompromisedPassword extends Constraint
{
    /** The code Symfony's constraint uses, so anything matching on it keeps matching. */
    public const string COMPROMISED_PASSWORD_ERROR = 'd9bcdbfe-a9d6-4bfa-a8ff-da5fd93e0f6d';

    protected const array ERROR_NAMES = [self::COMPROMISED_PASSWORD_ERROR => 'COMPROMISED_PASSWORD_ERROR'];

    /** The wording Symfony ships, so the translations it ships along with it are the ones shown. */
    // phpcs:ignore -- user-visible strings should not be split
    public string $message = 'This password has been leaked in a data breach, it must not be used. Please use another password.';

    /**
     * Whether a lookup that fails lets the password through. It does not: a service that is unreachable is no reason
     * to hand somebody an account with a breached password on it.
     */
    public bool $skipOnError = false;

    /**
     * @param string[]|null        $groups
     * @param array<string, mixed> $options
     */
    public function __construct(
        ?string $message = null,
        ?bool $skipOnError = null,
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
        $this->skipOnError = $skipOnError ?? $this->skipOnError;
    }
}
