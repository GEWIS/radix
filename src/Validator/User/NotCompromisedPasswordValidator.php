<?php

declare(strict_types=1);

namespace App\Validator\User;

use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_scalar;
use function sha1;
use function strtoupper;
use function trim;

class NotCompromisedPasswordValidator extends ConstraintValidator
{
    public function __construct(
        #[Autowire(service: 'pwned_passwords.client')]
        private readonly HttpClientInterface $pwnedPasswordsClient,
        #[Autowire('%app.user.compromised_password_check_enabled%')]
        private readonly bool $enabled,
    ) {
    }

    #[Override]
    public function validate(
        mixed $value,
        Constraint $constraint,
    ): void {
        if (!$constraint instanceof NotCompromisedPassword) {
            throw new UnexpectedTypeException(
                $constraint,
                NotCompromisedPassword::class,
            );
        }

        if (!$this->enabled) {
            return;
        }

        if (
            null === $value
            || '' === $value
        ) {
            return;
        }

        if (!is_scalar($value)) {
            throw new UnexpectedValueException(
                $value,
                'string',
            );
        }

        // The whole hash, in capitals: the service does not answer for a prefix, and it does not answer for lower
        // case either.
        $hash = strtoupper(sha1((string) $value));

        try {
            $answer = trim($this->pwnedPasswordsClient->request(
                'GET',
                $hash,
            )->getContent());
        } catch (ExceptionInterface $e) {
            if ($constraint->skipOnError) {
                return;
            }

            throw $e;
        }

        // `0` or `1`, and a number of appearances should the service ever start counting them.
        if (0 >= (int) $answer) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setCode(NotCompromisedPassword::COMPROMISED_PASSWORD_ERROR)
            ->addViolation();
    }
}
