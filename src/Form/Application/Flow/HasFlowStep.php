<?php

declare(strict_types=1);

namespace App\Form\Application\Flow;

/**
 * The step a flow is on, where {@see AbstractStepperFlowType} points `step_property_path`.
 */
trait HasFlowStep
{
    public ?string $step = null;
}
