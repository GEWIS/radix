<?php

declare(strict_types=1);

namespace App\Validator\Database;

use App\Repository\Database\MemberRepository;
use App\Repository\Database\ProspectiveMemberRepository;
use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

use function is_scalar;

class UnusedEmailAddressValidator extends ConstraintValidator
{
    public function __construct(
        private readonly MemberRepository $memberRepository,
        private readonly ProspectiveMemberRepository $prospectiveMemberRepository,
    ) {
    }

    #[Override]
    public function validate(
        mixed $value,
        Constraint $constraint,
    ): void {
        if (!$constraint instanceof UnusedEmailAddress) {
            throw new UnexpectedTypeException(
                $constraint,
                UnusedEmailAddress::class,
            );
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

        $address = (string) $value;

        if (
            !$this->memberRepository->hasMemberWith($address)
            && !$this->prospectiveMemberRepository->hasMemberWith($address)
        ) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setCode(UnusedEmailAddress::ALREADY_USED_ERROR)
            ->addViolation();
    }
}
