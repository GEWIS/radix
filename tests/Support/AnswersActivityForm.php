<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Activity\Enums\ActivityCategories;
use App\Form\Activity\ActivityFlow\ActivityData;
use DateTimeImmutable;

/**
 * The activity form with every question its general and details steps ask answered, standing on the given step.
 */
trait AnswersActivityForm
{
    private function answered(string $step): ActivityData
    {
        $data = new ActivityData();
        $data->step = $step;
        $data->organId = ActivityData::NONE;
        $data->companyId = ActivityData::NONE;
        $data->beginTime = new DateTimeImmutable('2030-06-01 18:00');
        $data->endTime = new DateTimeImmutable('2030-06-01 22:00');
        $data->category = ActivityCategories::Other;
        $data->languageEnglish = true;
        $data->nameEN = 'Test activity';
        $data->locationEN = 'Aula';
        $data->costsEN = 'Free';
        $data->descriptionEN = 'A talk.';

        return $data;
    }
}
