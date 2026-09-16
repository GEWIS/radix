<?php

declare(strict_types=1);

namespace App\Service\Activity;

use App\Entity\Activity\ActivityLabel;
use App\Entity\Activity\ActivityRevision;
use App\Form\Activity\ActivityFlow\ActivityData;
use App\Repository\Activity\ActivityLabelRepository;
use App\Repository\Career\CompanyRepository;
use App\Repository\Decision\OrganRepository;

use function array_filter;
use function array_map;

/**
 * Writes what the activity flow collected onto the working revision. The flow keeps the organ, the company and the
 * labels as identifiers, and turning those back into records needs the repositories.
 */
class ActivityFormMapper
{
    public function __construct(
        private readonly OrganRepository $organRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly ActivityLabelRepository $activityLabelRepository,
    ) {
    }

    public function apply(
        ActivityData $data,
        ActivityRevision $revision,
    ): void {
        $revision->organ = null !== ($organId = self::identifier($data->organId))
            ? $this->organRepository->find($organId)
            : null;
        $revision->company = null !== ($companyId = self::identifier($data->companyId))
            ? $this->companyRepository->find($companyId)
            : null;

        $revision->beginTime = $data->beginTime;
        $revision->endTime = $data->endTime;

        if (null !== $data->category) {
            $revision->category = $data->category;
        }

        $revision->requireGEFLITST = $data->requireGEFLITST;
        $revision->requireZettle = $data->requireZettle;

        $data->applyTexts($revision);

        $wanted = array_filter(array_map(
            $this->activityLabelRepository->find(...),
            $data->labelIds,
        ));

        /** @var ActivityLabel[] $current */
        $current = $revision->getLabels()->toArray();
        $revision->removeLabels($current);
        $revision->addLabels($wanted);
    }

    /**
     * The record a choice points at, or null where no organiser was chosen.
     */
    private static function identifier(?string $choice): ?int
    {
        if (
            null === $choice
            || ActivityData::NONE === $choice
        ) {
            return null;
        }

        return (int) $choice;
    }
}
