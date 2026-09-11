<?php

declare(strict_types=1);

namespace App\Service\Career;

use App\Entity\Career\Vacancy;
use App\Entity\Career\VacancyRevision;
use App\Form\Career\VacancyProfile\VacancyData;
use App\Repository\Career\CompanyJobPackageRepository;
use App\Repository\Career\VacancyLabelRepository;
use DateTime;

use function array_filter;
use function array_map;

/**
 * Writes what the vacancy flow collected onto the vacancy and its working revision. The flow keeps the package and
 * the labels as identifiers, and turning those back into records needs the repositories.
 */
class VacancyFormMapper
{
    public function __construct(
        private readonly CompanyJobPackageRepository $packageRepository,
        private readonly VacancyLabelRepository $vacancyLabelRepository,
    ) {
    }

    /**
     * `$identityEditable` and `$admin` say which fields the flow offered. A field that was not built could not have
     * been filled in, so writing it back would blank what is already there.
     */
    public function apply(
        VacancyData $data,
        Vacancy $vacancy,
        VacancyRevision $revision,
        bool $identityEditable,
        bool $admin,
    ): void {
        if ($identityEditable) {
            $vacancy->slugName = (string) $data->slugName;

            $package = null !== $data->packageId
                ? $this->packageRepository->find($data->packageId)
                : null;

            if (null !== $package) {
                $vacancy->package = $package;
            }
        }

        if ($admin) {
            $vacancy->published = $data->published;
        }

        if (null !== $data->category) {
            $revision->category = $data->category;
        }

        $revision->startDate = null !== $data->startDate
            ? DateTime::createFromInterface($data->startDate)
            : null;
        $revision->endDate = null !== $data->endDate
            ? DateTime::createFromInterface($data->endDate)
            : null;

        $data->applyTexts($revision);

        $revision->contactName = $data->contactName;
        $revision->contactEmail = $data->contactEmail;
        $revision->contactPhone = $data->contactPhone;

        $this->applyLabels(
            $data,
            $revision,
        );
    }

    private function applyLabels(
        VacancyData $data,
        VacancyRevision $revision,
    ): void {
        $wanted = array_filter(array_map(
            $this->vacancyLabelRepository->find(...),
            $data->labelIds,
        ));

        $revision->removeLabels($revision->getLabels()->toArray());
        $revision->addLabels($wanted);
    }
}
