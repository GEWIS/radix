<?php

declare(strict_types=1);

namespace App\Validator\Career;

use App\Form\Career\VacancyProfile\VacancyData;
use App\Repository\Career\CompanyJobPackageRepository;
use App\Repository\Career\VacancyRepository;
use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class ConsistentVacancyValidator extends ConstraintValidator
{
    public function __construct(
        private readonly VacancyRepository $vacancyRepository,
        private readonly CompanyJobPackageRepository $packageRepository,
    ) {
    }

    #[Override]
    public function validate(
        mixed $value,
        Constraint $constraint,
    ): void {
        if (!$constraint instanceof ConsistentVacancy) {
            throw new UnexpectedTypeException(
                $constraint,
                ConsistentVacancy::class,
            );
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof VacancyData) {
            throw new UnexpectedValueException(
                $value,
                VacancyData::class,
            );
        }

        if (
            null !== $value->startDate
            && null !== $value->endDate
            && $value->endDate < $value->startDate
        ) {
            $this->context->buildViolation($constraint->closesBeforeOpeningMessage)
                ->atPath('endDate')
                ->addViolation();
        }

        if (null === $value->packageId) {
            return;
        }

        $package = $this->packageRepository->find($value->packageId);

        if (null === $package) {
            return;
        }

        // A vacancy is invisible once its package expires whatever its own window says, so a window that runs past the
        // package would promise something it cannot keep. A package is already gone on the day it expires while a
        // vacancy is still open on its closing day, so the two dates cannot be the same either.
        if (
            null !== $value->endDate
            && $value->endDate >= $package->getExpirationDate()
        ) {
            $this->context->buildViolation($constraint->outlivesPackageMessage)
                ->atPath('endDate')
                ->addViolation();
        }

        // A vacancy is reached through its company and the category it sits in, so its slug only has to be free
        // within that pair. No single index holds that shape, which is why the database does not settle it and this
        // does.
        if (
            null === $value->slugName
            || null === $value->category
        ) {
            return;
        }

        $vacancy = null !== $value->vacancyId
            ? $this->vacancyRepository->find($value->vacancyId)
            : null;

        if (
            $this->vacancyRepository->isSlugNameUnique(
                $package->getCompany(),
                $value->slugName,
                $value->category,
                $vacancy,
            )
        ) {
            return;
        }

        $this->context->buildViolation($constraint->slugTakenMessage)
            ->atPath('slugName')
            ->addViolation();
    }
}
