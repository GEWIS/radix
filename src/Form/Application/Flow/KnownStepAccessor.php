<?php

declare(strict_types=1);

namespace App\Form\Application\Flow;

use Override;
use Symfony\Component\Form\Flow\FormFlowBuilderInterface;
use Symfony\Component\Form\Flow\StepAccessor\StepAccessorInterface;

use function array_key_exists;
use function array_key_first;

/**
 * A flow whose tail is built from records remembers a step that may no longer exist: the record was removed, here or
 * in another tab, after the step was stored. Symfony refuses to build a flow on such a step, so this answers with a
 * step that is always there instead, and writes it back so the flow carries on from it.
 */
final readonly class KnownStepAccessor implements StepAccessorInterface
{
    public function __construct(
        private StepAccessorInterface $inner,
        private FormFlowBuilderInterface $builder,
        private ?string $landing,
    ) {
    }

    /**
     * @param object|array<mixed> $data
     */
    #[Override]
    public function getStep(
        object|array $data,
        ?string $default = null,
    ): ?string {
        $step = $this->inner->getStep(
            $data,
            $default,
        );
        $steps = $this->builder->getSteps();

        if (
            null === $step
            || array_key_exists(
                $step,
                $steps,
            )
        ) {
            return $step;
        }

        $step = null !== $this->landing
            && array_key_exists(
                $this->landing,
                $steps,
            )
            ? $this->landing
            : array_key_first($steps);

        if (null !== $step) {
            $this->inner->setStep(
                $data,
                $step,
            );
        }

        return $step;
    }

    /**
     * @param object|array<mixed> $data
     */
    #[Override]
    public function setStep(
        object|array &$data,
        string $step,
    ): void {
        $this->inner->setStep(
            $data,
            $step,
        );
    }
}
